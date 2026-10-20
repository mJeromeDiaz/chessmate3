<?php

declare(strict_types=1);

namespace App\Vision\Training;

use App\Activity\Event\ExerciseCompleted;
use App\Activity\EventPublisher;
use App\Chess\InvalidPositionException;
use App\Chess\Rules;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Entity\Vision\Question;
use App\Enum\Activity\ExerciseType;
use App\Enum\Training\CloseReason;
use App\Enum\Training\Distraction;
use App\Enum\Training\Module;
use App\Enum\Vision\Ask;
use App\Enum\Vision\Kind;
use App\Puzzle\Catalog\PuzzleCatalog;
use App\Repository\Vision\QuestionRepository;
use App\Training\Exception\InvalidItemSubmissionException;
use App\Training\Exception\InvalidRunConfigException;
use App\Training\Exception\ItemAlreadySubmittedException;
use App\Training\Exception\ItemNotFoundException;
use App\Training\Exception\SubjectNotFoundException;
use App\Training\Exception\SubjectUnavailableException;
use App\Training\Module\CountedModuleInterface;
use App\Training\Module\Item;
use App\Training\Module\ItemResult;
use App\Training\Module\ItemSubmission;
use App\Training\Module\PreparedStep;
use App\Training\Module\Summary;
use App\Training\Module\TimeboxedModuleInterface;
use App\Vision\Drawing\LinePicker;
use App\Vision\Drawing\Draw;
use App\Vision\Drawing\LocateDrawer;
use App\Vision\Drawing\RelationDrawer;
use App\Vision\Drawing\TrackDrawer;
use App\Vision\Drawing\UpdateDrawer;
use App\Vision\VisionRules;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The vision module in runs (docs/VISION.md): a position shown, hidden, the moves announced, then a
 * question about the position they reach, answered on an empty board. Played from a mind map step
 * only for now ({@see Run::isPathStep()}): config `kind` ({@see Kind}), `count` (the questions, the
 * run's end), `lives` (the run closes at that many wrong answers) and `distraction` (a boss's, none
 * to strong, for the screen). A piece's path (`track_*`) moves that piece only; the mini-boss 3.1
 * asks three questions on each path, shown once. The subject is
 * the user; the server judges each answer. Never rated.
 *
 * @phpstan-type SanMove array{uci: string, san: string}
 */
final class VisionModule implements TimeboxedModuleInterface, CountedModuleInterface
{
    public const SUBJECT_TYPE = 'vision_player';
    public const ITEM_TYPE = 'vision';
    public const SOURCE_TYPE = 'vision_question';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QuestionRepository $questions,
        private readonly LocateDrawer $locate,
        private readonly LinePicker $lines,
        private readonly TrackDrawer $tracks,
        private readonly UpdateDrawer $updates,
        private readonly RelationDrawer $relations,
        private readonly PuzzleCatalog $catalog,
        private readonly EventPublisher $events,
    ) {
    }

    public function module(): Module
    {
        return Module::Vision;
    }

    public function subjectType(): string
    {
        return self::SUBJECT_TYPE;
    }

    public function isCounted(array $config): bool
    {
        return isset($config['count']);
    }

    public function prepare(User $user, array $settings, string $notes): PreparedStep
    {
        throw new InvalidRunConfigException('Vision is played from the mind map only for now.');
    }

    public function start(Run $run, \DateTimeImmutable $now): void
    {
        if (!$run->getSubjectId()->equals($run->getUser()->getId())) {
            throw new SubjectNotFoundException();
        }
        if (!$run->isPathStep()) {
            throw new InvalidRunConfigException('Vision is played from the mind map only for now.');
        }
        self::check($run->getConfig());
        // Served now: nothing to draw refuses the run instead of opening an empty one.
        $this->serve($run, $now);
    }

    public function next(Run $run, \DateTimeImmutable $now): Item
    {
        return $this->item($run, $this->questions->findPendingOfRun($run) ?? $this->serve($run, $now));
    }

    public function submit(Run $run, ItemSubmission $submission, \DateTimeImmutable $now): ItemResult
    {
        $question = Uuid::isValid($submission->itemId) ? $this->questions->findOwned(Uuid::fromString($submission->itemId), $run->getUser()) : null;
        if (null === $question || !$question->getRun()->getId()->equals($run->getId())) {
            throw new ItemNotFoundException();
        }
        if (!$question->isPending()) {
            throw new ItemAlreadySubmittedException();
        }
        [$right, $answer] = self::judge($question, $submission);
        $question->resolve($right, $answer, $now);
        $this->entityManager->flush();

        $puzzleId = $question->getPuzzleId();
        $this->events->publish(new ExerciseCompleted(
            userId: $run->getUser()->getId()->toRfc4122(),
            type: ExerciseType::Vision,
            success: $right,
            durationMs: $question->getDurationMs() ?? 0,
            itemCount: 1,
            sourceType: self::SOURCE_TYPE,
            sourceId: $question->getId()->toRfc4122(),
            occurredAt: $now,
            metadata: [
                'kind' => $question->getKind()->value,
                'ask' => $question->getAsk()->value,
                'status' => $question->getStatus()->value,
                'puzzleId' => null === $puzzleId ? null : $this->catalog->get($puzzleId)->getLichessId(),
                'trainingRunId' => $run->getId()->toRfc4122(),
            ],
        ));

        [, $count, $lives] = self::check($run->getConfig());
        $stats = $this->questions->statsOfRun($run);
        $resolved = $stats['right'] + $stats['wrong'];
        $closes = match (true) {
            null !== $lives && $stats['wrong'] >= $lives => 'lives_out',
            null !== $count && $resolved >= $count => 'count_done',
            default => null,
        };

        return new ItemResult(
            itemId: $question->getId()->toRfc4122(),
            success: $right,
            data: [
                'status' => $question->getStatus()->value,
                'expected' => $question->getExpected(),
                'answer' => $answer,
                'finalFen' => self::finalFen($question),
                'position' => $resolved,
                'count' => $count,
                'lives' => null === $lives ? null : max(0, $lives - $stats['wrong']),
                'durationMs' => $question->getDurationMs(),
            ],
            closes: null === $closes ? null : CloseReason::SubjectFinished,
            context: null === $closes ? [] : ['reason' => $closes],
        );
    }

    public function close(Run $run, CloseReason $reason, \DateTimeImmutable $now): void
    {
        // The question on screen at the end is not counted.
        $pending = $this->questions->findPendingOfRun($run);
        if (null !== $pending) {
            $this->entityManager->remove($pending);
        }
    }

    public function summarize(Run $run, \DateTimeImmutable $closedAt): Summary
    {
        $stats = $this->questions->statsOfRun($run);
        $played = $stats['right'] + $stats['wrong'];
        $kind = $run->getConfig()['kind'] ?? null;

        return new Summary(
            durationMs: max(0, (int) round(((float) $closedAt->format('U.u') - (float) $run->getStartedAt()->format('U.u')) * 1000)),
            itemCount: $played,
            successCount: $stats['right'],
            metrics: [
                'kind' => \is_string($kind) ? $kind : null,
                'right' => $stats['right'],
                'wrong' => $stats['wrong'],
                'activeMs' => $stats['activeMs'],
                'averageMs' => $played > 0 ? intdiv($stats['activeMs'], $played) : null,
            ],
        );
    }

    /**
     * A new question for the run.
     *
     * @throws SubjectUnavailableException no puzzle fits
     */
    private function serve(Run $run, \DateTimeImmutable $now): Question
    {
        [$kind] = self::check($run->getConfig());
        // The next question on the position just asked about, if it has more (the mini-bosses 3.x).
        $draw = $this->followUp($run) ?? match ($kind) {
            Kind::Locate => $this->locate->draw(),
            Kind::EnPrise => $this->lines->pick($run, Ask::EnPrise),
            Kind::Attackers => $this->lines->pick($run, Ask::Attackers),
            Kind::UpdateMissing, Kind::UpdateCaptures, Kind::UpdateMixed, Kind::UpdateBoss => $this->updates->draw($kind),
            Kind::RelationAttacked, Kind::RelationDefended, Kind::RelationAligned, Kind::RelationEnPrise, Kind::RelationMixed, Kind::RelationBoss => $this->relations->draw($kind),
            default => $this->tracks->draw($kind),
        };
        if (null === $draw) {
            throw new SubjectUnavailableException(CloseReason::SubjectUnavailable, ['reason' => 'no_puzzle'], 'No puzzle fits this question.');
        }
        $question = new Question($run, $kind, $draw, $now);
        $this->entityManager->persist($question);
        $this->entityManager->flush();

        return $question;
    }

    /**
     * The next question on the position (or path) just asked about, from its `followUps` (a
     * mini-boss's); null when it has none left and a new one starts.
     */
    private function followUp(Run $run): ?Draw
    {
        $last = $this->questions->findLastOfRun($run);
        $params = $last?->getParams() ?? [];
        $step = \is_int($params['step'] ?? null) ? $params['step'] : 0;
        $next = \is_array($params['followUps'] ?? null) ? ($params['followUps'][$step] ?? null) : null;
        $ask = \is_array($next) && \is_string($next['ask'] ?? null) ? Ask::tryFrom($next['ask']) : null;
        if (null === $last || !\is_array($next) || null === $ask) {
            return null;
        }
        $expected = \is_array($next['expected'] ?? null) ? array_values(array_filter($next['expected'], 'is_string')) : [];
        $target = \is_string($next['target'] ?? null) ? $next['target'] : null;
        $side = \is_string($next['side'] ?? null) ? $next['side'] : null;

        return new Draw($last->getFen(), $last->getAnnounced(), $ask, $target, $side, $expected, null, 0, ['step' => $step + 1] + $params);
    }

    /**
     * A path's moves as stored, always {uci, san} (MySQL keeps a JSON object's keys in its own order).
     *
     * @param array<mixed> $moves
     *
     * @return list<SanMove>
     */
    private static function pathMoves(array $moves): array
    {
        $clean = [];
        foreach ($moves as $move) {
            if (\is_array($move) && \is_string($move['uci'] ?? null) && \is_string($move['san'] ?? null)) {
                $clean[] = ['uci' => $move['uci'], 'san' => $move['san']];
            }
        }

        return $clean;
    }

    private function item(Run $run, Question $question): Item
    {
        [, $count, $lives, $distraction] = self::check($run->getConfig());
        $stats = $this->questions->statsOfRun($run);
        $params = $question->getParams();
        $moves = $params['moves'] ?? null;

        return new Item($question->getId()->toRfc4122(), self::ITEM_TYPE, [
            'kind' => $question->getKind()->value,
            'ask' => $question->getAsk()->value,
            'fen' => $question->getFen(),
            'visibleSeconds' => $question->getVisibleSeconds(),
            'hiddenSeconds' => VisionRules::HIDDEN_SECONDS,
            // A piece's path is no game: its moves come written already (« Rd1, Rd5, Rxd7 »).
            'announced' => \is_array($moves) ? self::pathMoves($moves) : self::sans($question->getFen(), $question->getAnnounced()),
            'announcedStyle' => \is_array($moves) ? 'path' : 'game',
            'target' => $question->getTarget(),
            'side' => $question->getSide(),
            'piece' => $params['piece'] ?? null,
            'to' => $params['to'] ?? null,
            'choices' => match ($question->getAsk()) {
                Ask::KnightJumps => ['1', '2', '3', '4', '5', '6'],
                Ask::Passed, Ask::Reach => ['yes', 'no'],
                default => null,
            },
            // A question on the path of the one before: nothing shown again.
            'followUp' => ($params['step'] ?? 0) > 0,
            'distraction' => $distraction,
            'position' => $stats['right'] + $stats['wrong'] + 1,
            'count' => $count,
            'lives' => null === $lives ? null : max(0, $lives - $stats['wrong']),
            'startedAt' => $question->getStartedAt()->format(\DATE_ATOM),
        ]);
    }

    /**
     * The answer as given and whether it is right: a square of the piece asked or of a piece en
     * prise; the piece on the square asked ('' for « empty »); every attacker, nothing more.
     *
     * @return array{bool, list<string>}
     *
     * @throws InvalidItemSubmissionException
     */
    private static function judge(Question $question, ItemSubmission $submission): array
    {
        $expected = $question->getExpected();
        $squares = array_values(array_unique($submission->squares));
        if (\count($squares) > VisionRules::MAX_SQUARES) {
            throw new InvalidItemSubmissionException('Too many squares.');
        }
        $ask = $question->getAsk();
        if ($ask->isChoice()) {
            if (null === $submission->choice) {
                throw new InvalidItemSubmissionException('A choice is expected.');
            }

            return [$submission->choice === ($expected[0] ?? null), [$submission->choice]];
        }
        if (Ask::Where === $ask || Ask::EnPrise === $ask || Ask::Arrival === $ask) {
            if (1 !== \count($squares)) {
                throw new InvalidItemSubmissionException('One square is expected.');
            }

            return [\in_array($squares[0], $expected, true), $squares];
        }
        if (Ask::What === $ask || Ask::Captured === $ask) {
            if (\count($submission->placement) > 1) {
                throw new InvalidItemSubmissionException('One piece, or none, is expected.');
            }
            $placed = $submission->placement[0] ?? null;
            if (null !== $placed && $placed['square'] !== $question->getTarget()) {
                throw new InvalidItemSubmissionException('The piece goes on the square asked.');
            }
            $piece = null === $placed ? '' : $placed['piece'];

            return [$piece === ($expected[0] ?? ''), [$piece]];
        }
        sort($squares);

        return [$squares === $expected, $squares];
    }

    /**
     * The position the announced moves reach (the one asked about).
     */
    private static function finalFen(Question $question): string
    {
        $final = $question->getParams()['finalFen'] ?? null;
        if (\is_string($final)) {
            return $final;
        }
        try {
            $rules = Rules::fromFen($question->getFen());
        } catch (InvalidPositionException) {
            return $question->getFen();
        }
        foreach ($question->getAnnounced() as $uci) {
            if (null === $rules->playUci($uci)) {
                break;
            }
        }

        return $rules->fen();
    }

    /**
     * @param list<string> $ucis
     *
     * @return list<SanMove>
     */
    private static function sans(string $fen, array $ucis): array
    {
        try {
            $rules = Rules::fromFen($fen);
        } catch (InvalidPositionException) {
            return [];
        }
        $moves = [];
        foreach ($ucis as $uci) {
            $move = $rules->playUci($uci);
            if (null === $move) {
                break;
            }
            $moves[] = ['uci' => $uci, 'san' => $move->san ?? $uci];
        }

        return $moves;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{Kind, int|null, int|null, string}
     *
     * @throws InvalidRunConfigException
     */
    private static function check(array $config): array
    {
        if ([] !== array_diff(array_keys($config), ['kind', 'count', 'lives', 'distraction'])) {
            throw new InvalidRunConfigException('Unknown option: only kind, count, lives and distraction are accepted.');
        }
        $distraction = $config['distraction'] ?? Distraction::None->value;
        if (!\is_string($distraction) || null === Distraction::tryFrom($distraction)) {
            throw new InvalidRunConfigException(\sprintf('distraction must be one of %s.', implode(', ', Distraction::values())));
        }
        $kind = \is_string($config['kind'] ?? null) ? Kind::tryFrom($config['kind']) : null;
        if (null === $kind) {
            throw new InvalidRunConfigException(\sprintf('kind must be one of %s.', implode(', ', Kind::values())));
        }

        return [$kind, self::limit($config, 'count', VisionRules::MAX_COUNT), self::limit($config, 'lives', VisionRules::MAX_LIVES), $distraction];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws InvalidRunConfigException
     */
    private static function limit(array $config, string $option, int $max): ?int
    {
        $value = $config[$option] ?? null;
        if (null === $value) {
            return null;
        }
        if (!\is_int($value) || $value < 1 || $value > $max) {
            throw new InvalidRunConfigException(\sprintf('%s must be a whole number from 1 to %d.', $option, $max));
        }

        return $value;
    }
}
