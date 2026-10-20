<?php

declare(strict_types=1);

namespace App\MindMap\Boss;

use App\Activity\Event\ExerciseCompleted;
use App\Activity\EventPublisher;
use App\Chess\InvalidPositionException;
use App\Chess\Rules;
use App\Entity\MindMap\BossItem;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Activity\ExerciseType;
use App\Enum\Coordinates\Mode;
use App\Enum\MindMap\BossQuestion;
use App\Enum\Notation\Kind;
use App\Enum\Training\CloseReason;
use App\Enum\Training\Distraction;
use App\Enum\Training\Module;
use App\Notation\Drawing\PuzzlePicker;
use App\Notation\Judge\WrittenLine;
use App\Repository\MindMap\BossItemRepository;
use App\Training\Exception\InvalidItemSubmissionException;
use App\Training\Exception\InvalidRunConfigException;
use App\Training\Exception\ItemAlreadySubmittedException;
use App\Training\Exception\ItemNotFoundException;
use App\Training\Exception\SubjectNotFoundException;
use App\Training\Module\CountedModuleInterface;
use App\Training\Module\Item;
use App\Training\Module\ItemResult;
use App\Training\Module\ItemSubmission;
use App\Training\Module\PreparedStep;
use App\Training\Module\Summary;
use App\Training\Module\TimeboxedModuleInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The Jongleur, boss of the visualisation path's chapter 1 (docs/MIND_MAP.md, § 8), as a run
 * launched by its path's step only ({@see Run::isPathStep()}): the subject is the user, config
 * `count` (the questions to answer, the run's end). Quick questions of
 * the chapter's exercises, drawn one at a time ({@see QuestionDrawer}): find, name or colour a
 * square (white's side), play a move announced or write a move shown played in a puzzle's position.
 * Each has {@see JongleurRules::QUESTION_SECONDS}, on the server's clock: a late answer, or none, is
 * a failure. The run ends at its count, or at the failure that takes the last life. No XP of its
 * own; the mind map judges the run ({@see \App\MindMap\Progress\PassRecorder}).
 */
final class JongleurModule implements TimeboxedModuleInterface, CountedModuleInterface
{
    public const SUBJECT_TYPE = 'mind_map_player';
    public const ITEM_TYPE = 'jongleur';
    public const SOURCE_TYPE = 'training_run';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BossItemRepository $items,
        private readonly PuzzlePicker $picker,
        private readonly QuestionDrawer $drawer,
        private readonly EventPublisher $events,
    ) {
    }

    public function module(): Module
    {
        return Module::Jongleur;
    }

    public function subjectType(): string
    {
        return self::SUBJECT_TYPE;
    }

    public function isCounted(array $config): bool
    {
        return true;
    }

    public function prepare(User $user, array $settings, string $notes): PreparedStep
    {
        throw new InvalidRunConfigException('The Jongleur is fought from the mind map only.');
    }

    public function start(Run $run, \DateTimeImmutable $now): void
    {
        if (!$run->getSubjectId()->equals($run->getUser()->getId())) {
            throw new SubjectNotFoundException();
        }
        if (!$run->isPathStep()) {
            throw new InvalidRunConfigException('The Jongleur is fought from the mind map only.');
        }
        self::count($run->getConfig());
        $this->serve($run, $now);
    }

    public function next(Run $run, \DateTimeImmutable $now): Item
    {
        return $this->item($run, $this->items->findPendingOfRun($run) ?? $this->serve($run, $now));
    }

    public function submit(Run $run, ItemSubmission $submission, \DateTimeImmutable $now): ItemResult
    {
        $item = Uuid::isValid($submission->itemId) ? $this->items->findOwned(Uuid::fromString($submission->itemId), $run->getUser()) : null;
        if (null === $item || !$item->getRun()->getId()->equals($run->getId())) {
            throw new ItemNotFoundException();
        }
        if (!$item->isPending()) {
            throw new ItemAlreadySubmittedException();
        }
        if (\count($submission->answers) > 1 || \count($submission->moves) > 1 || \count($submission->written) > 1) {
            throw new InvalidItemSubmissionException('One answer is expected.');
        }
        // Nothing sent: the question's time ran out on the client.
        $answer = match ($item->getQuestion()) {
            BossQuestion::Find, BossQuestion::Name, BossQuestion::Color => $submission->answers[0]['square'] ?? null,
            BossQuestion::Play => $submission->moves[0] ?? null,
            BossQuestion::Write => $submission->written[0] ?? null,
        };
        $item->resolve(null !== $answer && $this->isRight($item, $answer), JongleurRules::isLate($item->getStartedAt(), $now), $answer, $now);
        $this->entityManager->flush();

        $count = self::count($run->getConfig());
        $stats = $this->items->statsOfRun($run);
        $resolved = $stats['right'] + $stats['wrong'];
        $closes = match (true) {
            $stats['wrong'] >= JongleurRules::LIVES => 'lives_out',
            $resolved >= $count => 'count_done',
            default => null,
        };

        return new ItemResult(
            itemId: $item->getId()->toRfc4122(),
            success: $item->isRight(),
            data: [
                'status' => $item->getStatus()->value,
                'expected' => $this->expectedOf($item),
                'answer' => $answer,
                'lives' => max(0, JongleurRules::LIVES - $stats['wrong']),
                'position' => $resolved,
                'count' => $count,
                'durationMs' => $item->getDurationMs(),
            ],
            closes: null === $closes ? null : CloseReason::SubjectFinished,
            context: null === $closes ? [] : ['reason' => $closes],
        );
    }

    public function close(Run $run, CloseReason $reason, \DateTimeImmutable $now): void
    {
        // The question on screen at the end is not counted.
        $pending = $this->items->findPendingOfRun($run);
        if (null !== $pending) {
            $this->entityManager->remove($pending);
            $this->entityManager->flush();
        }
        $stats = $this->items->statsOfRun($run);
        $resolved = $stats['right'] + $stats['wrong'];
        if (0 === $resolved) {
            return;
        }
        $closedAt = CloseReason::TimeUp === $reason ? min($now, $run->getExpiresAt()) : $now;
        // Same transaction as the closing: the exercise exists if and only if the run is closed.
        $this->events->publish(new ExerciseCompleted(
            userId: $run->getUser()->getId()->toRfc4122(),
            type: ExerciseType::Jongleur,
            success: self::won($run, $stats),
            durationMs: self::msBetween($run->getStartedAt(), $closedAt),
            itemCount: $resolved,
            sourceType: self::SOURCE_TYPE,
            sourceId: $run->getId()->toRfc4122(),
            occurredAt: $closedAt,
            metadata: [
                'right' => $stats['right'],
                'wrong' => $stats['wrong'],
                'late' => $stats['late'],
                'count' => self::count($run->getConfig()),
                'won' => self::won($run, $stats),
                'trainingRunId' => $run->getId()->toRfc4122(),
            ],
        ));
    }

    public function summarize(Run $run, \DateTimeImmutable $closedAt): Summary
    {
        $stats = $this->items->statsOfRun($run);

        return new Summary(
            durationMs: self::msBetween($run->getStartedAt(), $closedAt),
            itemCount: $stats['right'] + $stats['wrong'],
            successCount: $stats['right'],
            metrics: [
                'boss' => JongleurRules::BOSS,
                'count' => self::count($run->getConfig()),
                'right' => $stats['right'],
                'wrong' => $stats['wrong'],
                'late' => $stats['late'],
                'lives' => max(0, JongleurRules::LIVES - $stats['wrong']),
                'won' => self::won($run, $stats),
            ],
        );
    }

    /**
     * A new question: one of the least asked kinds; a square question when no puzzle can be drawn.
     */
    private function serve(Run $run, \DateTimeImmutable $now): BossItem
    {
        $asked = $this->items->findOfRun($run);
        $previous = [] === $asked ? null : $asked[\count($asked) - 1]->getSquare();
        $kinds = array_map(static fn (BossItem $item): BossQuestion => $item->getQuestion(), $asked);
        $question = $this->drawer->question($kinds);
        $exercise = $question->isSquare() ? null : $this->picker->pick($run, Kind::Play);
        $move = $exercise->expected[0] ?? null;
        if (!$question->isSquare() && null === $move) {
            $question = $this->drawer->question($kinds, [BossQuestion::Play, BossQuestion::Write]);
        }
        if (null !== $exercise && null !== $move && !$question->isSquare()) {
            $item = new BossItem($run, JongleurRules::BOSS, $question, null, $exercise->fen, $move, $now);
        } else {
            $square = $this->drawer->square($previous);
            $expected = BossQuestion::Color === $question ? Mode::colorOf($square) : $square;
            $item = new BossItem($run, JongleurRules::BOSS, $question, $square, null, $expected, $now);
        }
        $this->entityManager->persist($item);
        $this->entityManager->flush();

        return $item;
    }

    private function item(Run $run, BossItem $item): Item
    {
        $stats = $this->items->statsOfRun($run);
        $data = [
            'question' => $item->getQuestion()->value,
            'position' => $stats['right'] + $stats['wrong'] + 1,
            'count' => self::count($run->getConfig()),
            'lives' => max(0, JongleurRules::LIVES - $stats['wrong']),
            'seconds' => JongleurRules::QUESTION_SECONDS,
            'distraction' => \is_string($run->getConfig()['distraction'] ?? null) ? $run->getConfig()['distraction'] : Distraction::Light->value,
            'startedAt' => $item->getStartedAt()->format(\DATE_ATOM),
        ];
        $fen = $item->getFen();
        if (null === $fen) {
            $data['square'] = $item->getSquare();
        } else {
            $data['fen'] = $fen;
            $data['color'] = 'b' === (explode(' ', $fen)[1] ?? 'w') ? 'black' : 'white';
            // The move to play, with its SAN; the move to write, without (it is the answer).
            $data['move'] = BossQuestion::Play === $item->getQuestion()
                ? self::sanMove($fen, $item->getExpected())
                : ['uci' => $item->getExpected()];
        }

        return new Item($item->getId()->toRfc4122(), self::ITEM_TYPE, $data);
    }

    private function isRight(BossItem $item, string $answer): bool
    {
        if (BossQuestion::Write !== $item->getQuestion()) {
            return $answer === $item->getExpected();
        }
        try {
            return WrittenLine::judge($item->getFen() ?? '', [$answer], [$item->getExpected()])['correct'];
        } catch (InvalidPositionException) {
            throw new \LogicException('A Jongleur question with an invalid position.');
        }
    }

    /**
     * The right answer, for the verdict: a square, a colour, or the move with its SAN.
     *
     * @return string|array{uci: string, san: string}
     */
    private function expectedOf(BossItem $item): string|array
    {
        $fen = $item->getFen();

        return null === $fen ? $item->getExpected() : self::sanMove($fen, $item->getExpected());
    }

    /**
     * @return array{uci: string, san: string}
     */
    private static function sanMove(string $fen, string $uci): array
    {
        try {
            $move = Rules::fromFen($fen)->playUci($uci);
        } catch (InvalidPositionException) {
            $move = null;
        }

        return ['uci' => $uci, 'san' => $move->san ?? $uci];
    }

    /**
     * @param array{right: int, wrong: int, late: int} $stats
     */
    private static function won(Run $run, array $stats): bool
    {
        return $stats['wrong'] < JongleurRules::LIVES && $stats['right'] + $stats['wrong'] >= self::count($run->getConfig());
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws InvalidRunConfigException
     */
    private static function count(array $config): int
    {
        if ([] !== array_diff(array_keys($config), ['count', 'distraction'])) {
            throw new InvalidRunConfigException('Unknown option: only count and distraction are accepted.');
        }
        $distraction = $config['distraction'] ?? null;
        if (null !== $distraction && (!\is_string($distraction) || null === Distraction::tryFrom($distraction))) {
            throw new InvalidRunConfigException(\sprintf('distraction must be one of %s.', implode(', ', Distraction::values())));
        }
        $count = $config['count'] ?? null;
        if (!\is_int($count) || $count < 1 || $count > JongleurRules::MAX_COUNT) {
            throw new InvalidRunConfigException(\sprintf('count must be a whole number from 1 to %d.', JongleurRules::MAX_COUNT));
        }

        return $count;
    }

    private static function msBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return max(0, (int) round(((float) $to->format('U.u') - (float) $from->format('U.u')) * 1000));
    }
}
