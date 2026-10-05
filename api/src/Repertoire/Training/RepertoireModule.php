<?php

declare(strict_types=1);

namespace App\Repertoire\Training;

use App\Activity\Event\ExerciseCompleted;
use App\Activity\EventPublisher;
use App\Entity\Repertoire\Presentation;
use App\Entity\Repertoire\Repertoire;
use App\Entity\Repertoire\RunState;
use App\Entity\Repertoire\Segment;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Activity\ExerciseType;
use App\Enum\Repertoire\PresentationStatus;
use App\Enum\Repertoire\TestUnit;
use App\Enum\Training\CloseReason;
use App\Enum\Training\Module;
use App\Repertoire\Exception\NoPreparedMoveException;
use App\Repertoire\Exception\PositionNotFoundException;
use App\Repertoire\Exception\PreparedMoveChangedException;
use App\Repertoire\Srs\Reviewer;
use App\Repertoire\Training\State\Progress;
use App\Repertoire\Training\State\Question;
use App\Repertoire\Training\State\Read;
use App\Repertoire\Training\State\Unit;
use App\Repository\Repertoire\PresentationRepository;
use App\Repository\Repertoire\RepertoireRepository;
use App\Repository\Repertoire\RunStateRepository;
use App\Training\Exception\InvalidItemSubmissionException;
use App\Training\Exception\InvalidRunConfigException;
use App\Training\Exception\ItemAlreadySubmittedException;
use App\Training\Exception\ItemNotFoundException;
use App\Training\Exception\SubjectNotFoundException;
use App\Training\Exception\SubjectUnavailableException;
use App\Training\Module\Item;
use App\Training\Module\ItemResult;
use App\Training\Module\ItemSubmission;
use App\Training\Module\PreparedStep;
use App\Training\Module\ReviewItem;
use App\Training\Module\ReviewableModuleInterface;
use App\Training\Module\Summary;
use App\Training\Module\TimeboxedModuleInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The repertoire test in timed runs (docs/REPERTOIRE.md, docs/TRAINING.md). The subject is the
 * user; the run's config is its {@see Scope}. Units (segments, or whole lines) come in the order
 * of {@see UnitQueue}; an item is one user move to find, the moves before it being played for the
 * user (the context without asking, then the opponent's moves). Every answer goes to the user's
 * cards ({@see Reviewer}); a unit with a mistake is failed and comes back later in the run.
 *
 * At the end of the run, a unit in progress without mistake is ignored (interrupted), with a
 * mistake it is failed. A unit whose prepared move changed meanwhile (the editor, in another tab)
 * is dropped without penalty.
 */
final class RepertoireModule implements TimeboxedModuleInterface, ReviewableModuleInterface
{
    public const SUBJECT_TYPE = 'repertoire_owner';
    public const ITEM_TYPE = 'repertoire_move';
    /** A unit in a run review. */
    public const REVIEW_TYPE = 'repertoire_unit';
    public const SOURCE_TYPE = 'repertoire_presentation';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
        private readonly RepertoireRepository $repertoires,
        private readonly RunStateRepository $states,
        private readonly UnitCatalog $catalog,
        private readonly UnitLoader $loader,
        private readonly UnitQueue $queue,
        private readonly Reviewer $reviewer,
        private readonly EventPublisher $events,
        private readonly PresentationRepository $presentations,
    ) {
    }

    public function module(): Module
    {
        return Module::Repertoire;
    }

    public function subjectType(): string
    {
        return self::SUBJECT_TYPE;
    }

    /**
     * A session step tests whole repertoires (settings `repertoireIds`), by segments.
     */
    public function prepare(User $user, array $settings, string $notes): PreparedStep
    {
        if ([] !== array_diff(array_keys($settings), ['repertoireIds'])) {
            throw new InvalidRunConfigException('Unknown setting: only repertoireIds is accepted.');
        }
        $scope = Scope::fromConfig($settings);
        foreach ($scope->repertoireIds as $id) {
            $this->repertoires->findOwned(Uuid::fromString($id), $user) ?? throw new SubjectNotFoundException();
        }

        return new PreparedStep($user->getId(), ['repertoireIds' => $scope->repertoireIds]);
    }

    public function start(Run $run, \DateTimeImmutable $now): void
    {
        if (!$run->getSubjectId()->equals($run->getUser()->getId())) {
            throw new SubjectNotFoundException();
        }
        $scope = Scope::fromConfig($run->getConfig());
        $repertoires = [];
        foreach ($scope->repertoireIds as $id) {
            $repertoires[] = $this->repertoires->findOwned(Uuid::fromString($id), $run->getUser()) ?? throw new SubjectNotFoundException();
        }
        if (null !== $scope->rootPositionId) {
            $found = $this->connection->fetchOne(
                'SELECT 1 FROM repertoire_position WHERE id = ? AND repertoire_id = ?',
                [Uuid::fromString($scope->rootPositionId)->toBinary(), $repertoires[0]->getId()->toBinary()],
                [ParameterType::BINARY, ParameterType::BINARY],
            );
            if (false === $found) {
                throw new InvalidRunConfigException('rootPositionId is not a position of this repertoire.');
            }
        }
        $standings = $this->catalog->standings($repertoires, $scope, $now);
        if ([] === $standings) {
            throw new SubjectUnavailableException(CloseReason::SubjectUnavailable, ['reason' => 'nothing_to_test'], 'Nothing to test in this scope.');
        }
        $progress = new Progress($scope);
        $progress->queue = self::entries($this->queue->order($standings));
        $this->entityManager->persist(new RunState($run, $progress->toArray()));
    }

    public function next(Run $run, \DateTimeImmutable $now): Item
    {
        [$state, $progress] = $this->load($run);
        if (null === $progress->current || $progress->current->isDone()) {
            $this->startUnit($run, $progress, $now);
        }
        $unit = $progress->current ?? throw new \LogicException('A unit is being played.');
        $question = $unit->current() ?? throw new \LogicException('A unit has questions.');
        $question->servedAt ??= Read::instant($now);
        $state->setState($progress->toArray());

        return $this->item($unit);
    }

    public function submit(Run $run, ItemSubmission $submission, \DateTimeImmutable $now): ItemResult
    {
        [$state, $progress] = $this->load($run);
        $unit = $progress->current;
        [$unitId, $index] = array_pad(explode(':', $submission->itemId, 2), 2, '');
        if (null === $unit || $unitId !== $unit->id || !ctype_digit($index)) {
            throw new ItemNotFoundException();
        }
        if ((int) $index < $unit->cursor) {
            throw new ItemAlreadySubmittedException();
        }
        $question = $unit->current();
        if ((int) $index > $unit->cursor || null === $question || null === $question->servedAt) {
            throw new ItemNotFoundException();
        }
        $played = $submission->moves[0] ?? throw new InvalidItemSubmissionException('The move played is expected.');

        $elapsed = Read::ms(new \DateTimeImmutable($question->servedAt), $now);
        $thinkMs = min($submission->thinkMs ?? $elapsed, $elapsed);
        try {
            $answer = $this->reviewer->answer($run->getUser(), Uuid::fromString($unit->repertoireId), Uuid::fromString($question->positionId), $played, $thinkMs, $now, $run->getId(), $question->uci);
        } catch (PositionNotFoundException|NoPreparedMoveException|PreparedMoveChangedException) {
            $answer = null;
        }
        if (null === $answer) {
            // The repertoire changed under the unit: drop it, without penalty.
            $this->finish($run, $progress, $unit, $now, dropped: true);
            $unit->cursor = \count($unit->questions);
            $state->setState($progress->toArray());

            return new ItemResult($submission->itemId, false, ['status' => 'stale', 'correct' => null, 'expected' => null, 'comment' => null, 'rating' => null, 'unitDone' => true, 'unitSuccess' => null, 'retry' => false]);
        }

        $question->correct = $answer->correct;
        $question->answeredAt = Read::instant($now);
        ++$unit->cursor;
        $progress->count('positionsGraded');
        $success = null;
        $retry = false;
        if ($unit->isDone()) {
            [$success, $retry] = $this->finish($run, $progress, $unit, $now);
        }
        $state->setState($progress->toArray());

        return new ItemResult($submission->itemId, $answer->correct, [
            'status' => 'answered',
            'correct' => $answer->correct,
            'expected' => ['uci' => $question->uci, 'san' => $question->san],
            'comment' => $question->comment,
            'rating' => strtolower($answer->rating->name),
            'unitDone' => $unit->isDone(),
            'unitSuccess' => $success,
            'retry' => $retry,
        ]);
    }

    public function close(Run $run, CloseReason $reason, \DateTimeImmutable $now): void
    {
        [$state, $progress] = $this->load($run);
        $unit = $progress->current;
        if (null !== $unit && !$unit->isDone()) {
            $this->finish($run, $progress, $unit, $now, closing: true);
            $unit->cursor = \count($unit->questions);
        }
        $state->setState($progress->toArray());
    }

    public function summarize(Run $run, \DateTimeImmutable $closedAt): Summary
    {
        [, $progress] = $this->load($run);
        $counters = $progress->counters;
        $succeeded = $counters['succeeded'] ?? 0;

        return new Summary(
            Read::ms($run->getStartedAt(), $closedAt),
            $succeeded + ($counters['failed'] ?? 0),
            $succeeded,
            [
                'unit' => $progress->scope->unit->value,
                'recovered' => $counters['recovered'] ?? 0,
                'interrupted' => $counters['interrupted'] ?? 0,
                'dropped' => $counters['dropped'] ?? 0,
                'positionsGraded' => $counters['positionsGraded'] ?? 0,
                'rounds' => $progress->round,
                'repertoireIds' => $progress->scope->repertoireIds,
            ],
        );
    }

    /**
     * Takes the next unit of the queue (a new round when it is empty) that can still be played.
     *
     * @throws SubjectUnavailableException nothing left to test (the repertoires emptied meanwhile)
     */
    private function startUnit(Run $run, Progress $progress, \DateTimeImmutable $now): void
    {
        $user = $run->getUser();
        $newRound = false;
        $rebuilt = false;
        while (true) {
            if ([] === $progress->queue) {
                $repertoires = array_values(array_filter(array_map(
                    fn (string $id): ?Repertoire => $this->repertoires->findOwned(Uuid::fromString($id), $user),
                    $progress->scope->repertoireIds,
                )));
                $standings = $rebuilt ? [] : $this->catalog->standings($repertoires, $progress->scope, $now);
                if ([] === $standings) {
                    throw new SubjectUnavailableException(CloseReason::SubjectUnavailable, ['reason' => 'nothing_to_test'], 'Nothing left to test.');
                }
                $progress->queue = self::entries($this->queue->order($standings, $progress->last));
                ++$progress->round;
                $newRound = $rebuilt = true;
            }
            $entry = array_shift($progress->queue) ?? throw new \LogicException('The queue was filled.');
            $repertoire = $this->repertoires->findOwned(Uuid::fromString($entry['repertoireId']), $user);
            $plan = null === $repertoire ? null : $this->catalog->unit($repertoire, $progress->scope->unit, $entry['key']);
            if (null === $repertoire || null === $plan) {
                continue;
            }
            $loaded = $this->loader->load($plan);
            if ([] === $loaded['questions']) {
                continue;
            }

            $unitId = Uuid::v7();
            $rank = ($progress->presented[$plan->key] ?? 0) + 1;
            $progress->presented[$plan->key] = $rank;
            $segments = [];
            foreach ($plan->segmentIds() as $segmentId) {
                $presentation = new Presentation(
                    $user,
                    $repertoire,
                    $this->entityManager->getReference(Segment::class, Uuid::fromString($segmentId)) ?? throw new \LogicException('No segment.'),
                    $run,
                    $unitId,
                    $plan->unit,
                    $rank,
                    $progress->round,
                    $loaded['sans'][$segmentId] ?? [],
                    $plan->labels[$segmentId] ?? ['opening' => null, 'move' => null],
                    $now,
                    $loaded['starts'][$segmentId] ?? null,
                );
                $this->entityManager->persist($presentation);
                $segments[] = ['segmentId' => $segmentId, 'presentationId' => $presentation->getId()->toRfc4122()];
            }
            $progress->current = new Unit(
                $unitId->toRfc4122(),
                $entry['repertoireId'],
                $plan->key,
                $plan->unit,
                $rank,
                $progress->round,
                $entry['retry'],
                $newRound,
                Read::instant($now),
                $repertoire->getColor()->value,
                $loaded['context'],
                $plan->deviation,
                $plan->label(),
                $loaded['questions'],
                $segments,
            );

            return;
        }
    }

    /**
     * Logs the unit's presentations, one per segment, and counts the unit.
     *
     * - done: failed with a mistake (it comes back in the queue), succeeded otherwise;
     * - closing (end of the run): with a mistake, failed; without, ignored (interrupted);
     * - dropped (the repertoire changed): ignored.
     *
     * @return array{bool|null, bool} success (null when not counted), whether it comes back
     */
    private function finish(Run $run, Progress $progress, Unit $unit, \DateTimeImmutable $now, bool $closing = false, bool $dropped = false): array
    {
        $errors = $unit->errors();
        $ignored = $dropped || ($closing && 0 === $errors);
        $previousEnd = new \DateTimeImmutable($unit->startedAt);
        foreach ($unit->segments as $segment) {
            $questions = array_values(array_filter($unit->questions, static fn (Question $question): bool => $question->segmentId === $segment['segmentId']));
            $answered = array_values(array_filter($questions, static fn (Question $question): bool => null !== $question->answeredAt));
            $wrong = array_values(array_filter($questions, static fn (Question $question): bool => false === $question->correct));
            $status = match (true) {
                $ignored => PresentationStatus::Interrupted,
                [] !== $wrong => PresentationStatus::Failed,
                \count($answered) === \count($questions) => PresentationStatus::Succeeded,
                default => PresentationStatus::Interrupted,
            };
            $last = end($answered);
            $end = false === $last ? $previousEnd : new \DateTimeImmutable((string) $last->answeredAt);
            $presentation = $this->entityManager->find(Presentation::class, Uuid::fromString($segment['presentationId'])) ?? throw new \LogicException('No presentation.');
            $presentation->finish($status, [] === $wrong ? null : $wrong[0]->ply, \count($answered), $end, Read::ms($previousEnd, $end));
            if (PresentationStatus::Interrupted !== $status) {
                $this->publish($run, $presentation);
            }
            $previousEnd = $end;
        }

        if ($ignored) {
            $progress->count($dropped ? 'dropped' : 'interrupted');

            return [null, false];
        }
        $success = 0 === $errors;
        $progress->count($success ? 'succeeded' : 'failed');
        if ($success && isset($progress->failed[$unit->key])) {
            $progress->count('recovered');
        }
        $progress->last = $unit->key;
        if (!$success) {
            $progress->failed[$unit->key] = 1;
        }
        if ($success || $closing) {
            return [$success, false];
        }
        array_splice($progress->queue, UnitQueue::retryIndex(\count($progress->queue), TestUnit::Line === $unit->unit), 0, [
            ['repertoireId' => $unit->repertoireId, 'key' => $unit->key, 'retry' => true],
        ]);

        return [false, true];
    }

    private function publish(Run $run, Presentation $presentation): void
    {
        $this->events->publish(new ExerciseCompleted(
            userId: $run->getUser()->getId()->toRfc4122(),
            type: ExerciseType::RepertoireSegment,
            success: PresentationStatus::Succeeded === $presentation->getStatus(),
            durationMs: $presentation->getDurationMs() ?? 0,
            itemCount: $presentation->getPositionsGraded(),
            sourceType: self::SOURCE_TYPE,
            sourceId: $presentation->getId()->toRfc4122(),
            occurredAt: $presentation->getFinishedAt() ?? $presentation->getStartedAt(),
            metadata: [
                'repertoireId' => $presentation->getRepertoire()->getId()->toRfc4122(),
                'segmentId' => $presentation->getSegment()->getId()->toRfc4122(),
                'trainingRunId' => $run->getId()->toRfc4122(),
                'rank' => $presentation->getRank(),
                'firstErrorPly' => $presentation->getFirstErrorPly(),
                'unit' => $presentation->getUnit()->value,
            ],
        ));
    }

    /**
     * One item per unit presented, its segments' moves put end to end from the position the first
     * one starts from (null for units presented before it was kept: not replayable). A unit
     * failed if one of its segments failed; units not counted (interrupted) are left out.
     */
    public function review(Run $run): array
    {
        /** @var array<string, list<Presentation>> $units */
        $units = [];
        foreach ($this->presentations->findByRun($run) as $presentation) {
            $units[$presentation->getUnitId()->toRfc4122()][] = $presentation;
        }

        $items = [];
        foreach ($units as $unitId => $presentations) {
            $failed = false;
            $succeeded = true;
            foreach ($presentations as $presentation) {
                $failed = $failed || PresentationStatus::Failed === $presentation->getStatus();
                $succeeded = $succeeded && PresentationStatus::Succeeded === $presentation->getStatus();
            }
            if (!$failed && !$succeeded) {
                continue;
            }
            $first = $presentations[0];
            // A line is labelled by its last segment.
            $last = $presentations[\count($presentations) - 1];
            $durations = array_filter(array_map(static fn (Presentation $presentation): ?int => $presentation->getDurationMs(), $presentations), static fn (?int $ms): bool => null !== $ms);
            $errorPlies = array_filter(array_map(static fn (Presentation $presentation): ?int => $presentation->getFirstErrorPly(), $presentations), static fn (?int $ply): bool => null !== $ply);
            $repertoire = $first->getRepertoire();

            $items[] = new ReviewItem(
                self::REVIEW_TYPE,
                $failed ? ReviewItem::FAIL : ReviewItem::OK,
                [] === $durations ? null : array_sum($durations),
                [
                    'unitId' => $unitId,
                    'unit' => $first->getUnit()->value,
                    'rank' => $first->getRank(),
                    'round' => $first->getRound(),
                    'repertoireId' => $repertoire->getId()->toRfc4122(),
                    'repertoireName' => $repertoire->getName(),
                    'orientation' => $repertoire->getColor()->value,
                    'label' => $last->getLabel(),
                    'startFen' => $first->getStartFen(),
                    'moves' => array_merge(...array_map(static fn (Presentation $presentation): array => $presentation->getMoves(), $presentations)),
                    'firstErrorPly' => [] === $errorPlies ? null : min($errorPlies),
                ],
            );
        }

        return $items;
    }

    private function item(Unit $unit): Item
    {
        $question = $unit->current() ?? throw new \LogicException('A unit has questions.');
        $data = [
            'unitId' => $unit->id,
            'repertoireId' => $unit->repertoireId,
            'segmentId' => $question->segmentId,
            'index' => $unit->cursor,
            'total' => \count($unit->questions),
            'play' => $question->play,
            'fen' => $question->fen,
            // Enough to show a question on its own (a page reloaded in the middle of a unit).
            'ply' => $question->ply,
            'unit' => $unit->unit->value,
            'orientation' => $unit->orientation,
            'label' => $unit->label,
            'start' => null,
        ];
        if (0 === $unit->cursor) {
            $data['start'] = [
                'unit' => $unit->unit->value,
                'orientation' => $unit->orientation,
                'context' => $unit->context,
                'deviation' => $unit->deviation,
                'label' => $unit->label,
                'rank' => $unit->rank,
                'round' => $unit->round,
                'retry' => $unit->retry,
                'newRound' => $unit->newRound,
            ];
        }

        return new Item($unit->id.':'.$unit->cursor, self::ITEM_TYPE, $data);
    }

    /**
     * @return array{RunState, Progress}
     */
    private function load(Run $run): array
    {
        $state = $this->states->findOneBy(['run' => $run]) ?? throw new \LogicException('The run has no repertoire state.');

        // Through JSON: the state may still hold what toArray() gave (objects for empty maps).
        $data = json_decode(json_encode($state->getState(), \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);

        return [$state, Progress::fromArray(\is_array($data) ? $data : [])];
    }

    /**
     * @param list<UnitStanding> $standings
     *
     * @return list<array{repertoireId: string, key: string, retry: bool}>
     */
    private static function entries(array $standings): array
    {
        return array_map(static fn (UnitStanding $unit): array => ['repertoireId' => $unit->repertoireId, 'key' => $unit->key, 'retry' => false], $standings);
    }
}
