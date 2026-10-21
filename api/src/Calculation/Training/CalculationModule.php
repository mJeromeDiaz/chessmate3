<?php

declare(strict_types=1);

namespace App\Calculation\Training;

use App\Activity\Event\ExerciseCompleted;
use App\Activity\EventPublisher;
use App\Calculation\CalculationRules;
use App\Calculation\Drawing\LinePicker;
use App\Calculation\Drawing\LineSpec;
use App\Calculation\Drawing\Question;
use App\Calculation\Drawing\QuestionDrawer;
use App\Calculation\Judge\IllegalLineException;
use App\Calculation\Judge\LineJudge;
use App\Calculation\Judge\LineShape;
use App\Chess\InvalidPositionException;
use App\Chess\Rules;
use App\Entity\Calculation\Attempt;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Activity\ExerciseType;
use App\Enum\Calculation\AttemptStatus;
use App\Enum\Calculation\Ending;
use App\Enum\Calculation\Kind;
use App\Enum\Training\CloseReason;
use App\Enum\Training\Module;
use App\Puzzle\Catalog\PuzzleCatalog;
use App\Puzzle\Rating\RatingCalculator;
use App\Repository\Calculation\AttemptRepository;
use App\Repository\Puzzle\RatingRepository;
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
use App\Training\Module\ReadyModuleInterface;
use App\Training\Module\ReviewableModuleInterface;
use App\Training\Module\ReviewItem;
use App\Training\Module\Summary;
use App\Training\Module\TimeboxedModuleInterface;
use App\Training\Run\TimeboxRunner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The calculation module in timed runs (docs/CALCULATION.md), played from the mind map only for now
 * ({@see Run::isPathStep()}). The subject is the user; config `moves` (the player's moves of the
 * line, 1 to 4, or a list of them), `ending` (mate or material; any without), `themes` (Lichess
 * themes, OR; none: by its mate or its length), `mate` (chapter 1: `moves` and a mate in one),
 * `capture` (a capture on the way), `question` (each line followed by a question on the position
 * its solution reaches: where a piece stands, what stands on a square; untimed, answered on an
 * empty board), `solve` (each puzzle solved first, the opponent answering, its solution given to
 * the client for it; then replayed from memory at once, both sides: a solving missed is a failure),
 * `count` (the items, lines and questions, the run's end), `lives` (the run closes at that many
 * failures) and `kind` (chapter 3, judged by the engine in the browser: a reply to choose, a line
 * against each of two replies, a mate threat to parry, a check in reply; docs/CALCULATION.md,
 * § 2 quater). A position is thought over on a frozen board, up to THINK_SECONDS;
 * « Je réponds » ({@see self::ready()}) starts the answer's clock: PLY_SECONDS per half-move of the
 * solution, on the server's clock; the whole line, both sides, is then judged once
 * ({@see LineJudge}). Late, or not played (`pass`): a failure. Never rated.
 *
 * The Métronome (docs/CALCULATION.md, § 6): config `streak` (the lines right in a row to win, in
 * place of `mate`) and `lives`: mates of 1 to 4 moves, longer on average as lines come right, their
 * length never told; the thinking time shrinks with each line right ({@see CalculationRules::tempo()});
 * a failure breaks the streak and costs a life; won at the streak, lost at the last life. No XP per
 * line (the boss's reward). Its summary's `metrics.failures` is its lives when the streak was not
 * reached, so that the path judges it lost.
 *
 * @phpstan-type SanMove array{uci: string, san: string}
 * @phpstan-type Config array{spec: LineSpec|null, kind: Kind, capture: bool, question: bool, solve: bool, count: int|null, lives: int|null, streak: int|null}
 */
final class CalculationModule implements TimeboxedModuleInterface, ReviewableModuleInterface, CountedModuleInterface, ReadyModuleInterface
{
    public const SUBJECT_TYPE = 'calculation_player';
    public const ITEM_TYPE = 'calculation';
    public const SOURCE_TYPE = 'calculation_attempt';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AttemptRepository $attempts,
        private readonly LinePicker $picker,
        private readonly PuzzleCatalog $catalog,
        private readonly RatingRepository $ratings,
        private readonly EventPublisher $events,
    ) {
    }

    public function module(): Module
    {
        return Module::Calculation;
    }

    public function subjectType(): string
    {
        return self::SUBJECT_TYPE;
    }

    public function isCounted(array $config): bool
    {
        return isset($config['count']) || isset($config['streak']);
    }

    public function prepare(User $user, array $settings, string $notes): PreparedStep
    {
        throw new InvalidRunConfigException('Calculation is played from the mind map only for now.');
    }

    public function start(Run $run, \DateTimeImmutable $now): void
    {
        if (!$run->getSubjectId()->equals($run->getUser()->getId())) {
            throw new SubjectNotFoundException();
        }
        if (!$run->isPathStep()) {
            throw new InvalidRunConfigException('Calculation is played from the mind map only for now.');
        }
        self::check($run->getConfig());
        // Served now: nothing to draw refuses the run instead of opening an empty one.
        $this->serve($run, $now);
    }

    public function next(Run $run, \DateTimeImmutable $now): Item
    {
        return $this->item($run, $this->attempts->findPendingOfRun($run) ?? $this->serve($run, $now));
    }

    public function ready(Run $run, string $itemId, \DateTimeImmutable $now): array
    {
        $attempt = $this->pendingAttempt($run, $itemId);
        // Past the thinking time, the answer's clock started by itself.
        $attempt->ready(min($now, self::thinkingEnd($attempt)));
        $this->entityManager->flush();

        return ['readyAt' => $attempt->getReadyAt()?->format(\DATE_ATOM)];
    }

    public function submit(Run $run, ItemSubmission $submission, \DateTimeImmutable $now): ItemResult
    {
        $attempt = $this->pendingAttempt($run, $submission->itemId);
        if ($attempt->isQuestion()) {
            return $this->answer($run, $attempt, $submission, $now);
        }
        if ($attempt->getKind()->isEngine()) {
            return $this->engineAnswer($run, $attempt, $submission, $now);
        }
        $played = $submission->pass ? [] : $submission->moves;
        if (\count($played) > 2 * CalculationRules::MAX_MATE) {
            throw new InvalidItemSubmissionException('More half-moves than a mate has.');
        }
        // The mini-boss: the puzzle not solved, its solution shown; nothing to replay.
        $unsolved = $submission->solutionShown && self::check($run->getConfig())['solve'];
        if (!$submission->pass && !$unsolved && [] === $played) {
            throw new InvalidItemSubmissionException('A line, or pass when the time ran out.');
        }
        $mistakeAt = null;
        if ($unsolved) {
            $status = AttemptStatus::Wrong;
            $played = [];
        } elseif (self::isLate($attempt, $now) || $submission->pass) {
            $status = AttemptStatus::Late;
        } else {
            try {
                $judged = LineJudge::judge($attempt->getFen(), $attempt->getExpected(), $played);
            } catch (IllegalLineException $e) {
                throw new InvalidItemSubmissionException($e->getMessage());
            } catch (InvalidPositionException) {
                throw new \LogicException('A calculation item with an invalid position.');
            }
            $status = $judged['right'] ? AttemptStatus::Right : AttemptStatus::Wrong;
            $mistakeAt = $judged['mistakeAt'];
        }
        $attempt->resolve($status, $played, $mistakeAt, $now);
        $this->entityManager->flush();
        $right = AttemptStatus::Right === $status;

        $this->events->publish(new ExerciseCompleted(
            userId: $run->getUser()->getId()->toRfc4122(),
            type: ExerciseType::Calculation,
            success: $right,
            durationMs: $attempt->getDurationMs() ?? 0,
            itemCount: 1,
            sourceType: self::SOURCE_TYPE,
            sourceId: $attempt->getId()->toRfc4122(),
            occurredAt: $now,
            metadata: [
                'kind' => 'line',
                'lineKind' => Kind::Line->value,
                // The line's moves (named before lines without mate came), and how it ends.
                'mate' => $attempt->getMoves(),
                'ending' => $attempt->getEnding()->value,
                'status' => $status->value,
                // The Métronome: no XP per line.
                'boss' => null !== self::check($run->getConfig())['streak'],
                'puzzleId' => $this->catalog->get($attempt->getPuzzleId())->getLichessId(),
                'trainingRunId' => $run->getId()->toRfc4122(),
            ],
        ));

        return $this->result($run, $attempt, [
            'kind' => 'line',
            'expected' => self::sans($attempt->getFen(), $attempt->getExpected()),
            'answer' => self::sans($attempt->getFen(), $played),
        ]);
    }

    /**
     * A line of chapter 3 (docs/CALCULATION.md, § 2 quater), judged by Stockfish in the browser: the
     * server checks the moves are legal and the line's shape (to its end, or the player's mate),
     * the time, and takes the engine's verdict (docs/ENGINE.md, § 3). A line on the solution is
     * right whatever the verdict.
     *
     * @throws InvalidItemSubmissionException
     */
    private function engineAnswer(Run $run, Attempt $attempt, ItemSubmission $submission, \DateTimeImmutable $now): ItemResult
    {
        $kind = $attempt->getKind();
        $fen = $attempt->getFen();
        $expected = $attempt->getExpected();
        if (!$submission->pass && null === $submission->engine) {
            throw new InvalidItemSubmissionException('The engine\'s verdict is expected.');
        }
        $verdict = (bool) ($submission->engine['right'] ?? false);
        $given = $submission->engine['mistakeAt'] ?? null;
        $answer = [];
        $second = null;
        $mistakeAt = null;
        try {
            if (Kind::Reply === $kind) {
                // The reply chosen, after the player's move shown played.
                if (!$submission->pass) {
                    $choice = $submission->choice;
                    if (null === $choice || !\in_array($choice, LineShape::replies($fen, $expected[0]), true)) {
                        throw new InvalidItemSubmissionException('One of the opponent\'s legal replies.');
                    }
                    $answer = [$choice];
                }
                // Chosen within the thinking time (stored to the second: one more, and the network's).
                $late = $submission->pass || (int) round((float) $now->format('U.u') * 1000) > ((int) self::thinkingEnd($attempt)->format('U') + 1) * 1000 + TimeboxRunner::SUBMISSION_TOLERANCE_MS;
                $right = $verdict || ($answer === [$expected[1] ?? null]);
                $mistakeAt = 1;
            } elseif (Kind::TwoReplies === $kind) {
                $lines = $submission->pass ? [] : $submission->lines;
                if (!$submission->pass && CalculationRules::TWO_REPLIES !== \count($lines)) {
                    throw new InvalidItemSubmissionException('Two lines, one against each reply.');
                }
                [$answer, $second] = [$lines[0] ?? [], $lines[1] ?? []];
                $complete = !$submission->pass;
                foreach ($lines as $line) {
                    $complete = LineShape::complete($fen, $line, \count($expected)) && $complete;
                }
                if ($complete) {
                    // The same first move, then each of the two replies.
                    $replies = LineShape::replies($fen, $answer[0]);
                    if (\count($answer) < 2 || \count($second) < 2 || $answer[0] !== $second[0] || $answer[1] === $second[1] || [] !== array_diff([$answer[1], $second[1]], $replies)) {
                        throw new InvalidItemSubmissionException('The same first move, then each of the two replies.');
                    }
                }
                $late = $submission->pass || self::isLate($attempt, $now, CalculationRules::TWO_REPLIES);
                $right = $complete && $verdict;
                $mistakeAt = $complete ? $given : \count($answer) + \count($second);
            } else {
                $answer = $submission->pass ? [] : $submission->moves;
                $complete = !$submission->pass && LineShape::complete($fen, $answer, \count($expected));
                $late = $submission->pass || self::isLate($attempt, $now);
                $onSolution = $complete && LineJudge::judge($fen, $expected, $answer)['right'];
                $right = $onSolution || ($complete && $verdict);
                // The mini-boss: another combination chosen alone, refused at once.
                $refused = Kind::Combo === $kind && 1 === \count($answer) && $answer[0] !== ($expected[0] ?? null);
                $mistakeAt = $complete ? $given : ($refused ? 0 : \count($answer));
            }
        } catch (IllegalLineException $e) {
            throw new InvalidItemSubmissionException($e->getMessage());
        } catch (InvalidPositionException) {
            throw new \LogicException('A calculation item with an invalid position.');
        }
        $status = match (true) {
            $late => AttemptStatus::Late,
            $right => AttemptStatus::Right,
            default => AttemptStatus::Wrong,
        };
        $attempt->resolve($status, $answer, $mistakeAt, $now, $second);
        $this->entityManager->flush();
        $right = AttemptStatus::Right === $status;

        $this->events->publish(new ExerciseCompleted(
            userId: $run->getUser()->getId()->toRfc4122(),
            type: ExerciseType::Calculation,
            success: $right,
            durationMs: $attempt->getDurationMs() ?? 0,
            itemCount: 1,
            sourceType: self::SOURCE_TYPE,
            sourceId: $attempt->getId()->toRfc4122(),
            occurredAt: $now,
            metadata: [
                'kind' => 'line',
                'lineKind' => $kind->value,
                'mate' => $attempt->getMoves(),
                'ending' => $attempt->getEnding()->value,
                'status' => $status->value,
                'boss' => false,
                'puzzleId' => $this->catalog->get($attempt->getPuzzleId())->getLichessId(),
                'trainingRunId' => $run->getId()->toRfc4122(),
            ],
        ));

        return $this->result($run, $attempt, [
            'kind' => $kind->value,
            'expected' => self::sans($fen, $expected),
            // 3.1: the player's move, then the reply chosen.
            'answer' => self::sans($fen, Kind::Reply === $kind ? [$expected[0], ...$answer] : $answer),
            'second' => null === $second ? null : self::sans($fen, $second),
        ]);
    }

    /**
     * A question's answer (docs/CALCULATION.md, § 2 bis): `squares` (one square holding the piece),
     * or `placement` (the piece on the square asked, nothing for an empty one). Never late.
     *
     * @throws InvalidItemSubmissionException
     */
    private function answer(Run $run, Attempt $attempt, ItemSubmission $submission, \DateTimeImmutable $now): ItemResult
    {
        if (Question::WHERE === $attempt->getAsk()) {
            $square = $submission->squares[0] ?? null;
            if (1 !== \count($submission->squares) || !\is_string($square) || 1 !== preg_match('/^[a-h][1-8]$/', $square)) {
                throw new InvalidItemSubmissionException('One square, where the piece stands.');
            }
            $answer = [$square];
            $right = \in_array($square, $attempt->getExpected(), true);
        } else {
            $placed = $submission->placement[0] ?? null;
            if (\count($submission->placement) > 1 || (null !== $placed && ($placed['square'] !== $attempt->getTarget() || 1 !== preg_match('/^[KQRBNPkqrbnp]$/', $placed['piece'])))) {
                throw new InvalidItemSubmissionException('The piece on the square asked, or nothing.');
            }
            $answer = null === $placed ? [] : [$placed['piece']];
            $right = $answer === $attempt->getExpected();
        }
        $status = $right ? AttemptStatus::Right : AttemptStatus::Wrong;
        $attempt->resolve($status, $answer, null, $now);
        $this->entityManager->flush();

        $this->events->publish(new ExerciseCompleted(
            userId: $run->getUser()->getId()->toRfc4122(),
            type: ExerciseType::Calculation,
            success: $right,
            durationMs: $attempt->getDurationMs() ?? 0,
            itemCount: 1,
            sourceType: self::SOURCE_TYPE,
            sourceId: $attempt->getId()->toRfc4122(),
            occurredAt: $now,
            metadata: [
                'kind' => 'question',
                'ask' => $attempt->getAsk(),
                'mate' => $attempt->getMoves(),
                'ending' => $attempt->getEnding()->value,
                'status' => $status->value,
                'boss' => false,
                'puzzleId' => $this->catalog->get($attempt->getPuzzleId())->getLichessId(),
                'trainingRunId' => $run->getId()->toRfc4122(),
            ],
        ));

        return $this->result($run, $attempt, [
            'kind' => 'question',
            'ask' => $attempt->getAsk(),
            'target' => $attempt->getTarget(),
            // The position reached, for the correction.
            'fen' => $attempt->getFen(),
            'expected' => $attempt->getExpected(),
            'answer' => $answer,
        ]);
    }

    /**
     * An item's verdict, and the run's end when it comes: the streak reached, the lives spent, the
     * count done.
     *
     * @param array<string, mixed> $data the item's own
     */
    private function result(Run $run, Attempt $attempt, array $data): ItemResult
    {
        $right = AttemptStatus::Right === $attempt->getStatus();
        $status = $attempt->getStatus();
        $config = self::check($run->getConfig());
        $stats = $this->attempts->statsOfRun($run);
        $resolved = $stats['right'] + $stats['wrong'] + $stats['late'];
        $failures = $stats['wrong'] + $stats['late'];
        $closes = match (true) {
            null !== $config['streak'] && $stats['streak'] >= $config['streak'] => 'streak_done',
            null !== $config['lives'] && $failures >= $config['lives'] => 'lives_out',
            null !== $config['count'] && $resolved >= $config['count'] => 'count_done',
            default => null,
        };

        return new ItemResult(
            itemId: $attempt->getId()->toRfc4122(),
            success: $right,
            data: $data + [
                'status' => $status->value,
                'mistakeAt' => $attempt->getMistakeAt(),
                'position' => $resolved,
                'count' => $config['count'],
                'lives' => null === $config['lives'] ? null : max(0, $config['lives'] - $failures),
                'streak' => $stats['streak'],
                'streakTarget' => $config['streak'],
                'moves' => $attempt->getMoves(),
                'ending' => $attempt->getEnding()->value,
                'durationMs' => $attempt->getDurationMs(),
            ],
            closes: null === $closes ? null : CloseReason::SubjectFinished,
            context: null === $closes ? [] : ['reason' => $closes],
        );
    }

    public function close(Run $run, CloseReason $reason, \DateTimeImmutable $now): void
    {
        // The line on screen at the end is not counted.
        $pending = $this->attempts->findPendingOfRun($run);
        if (null !== $pending) {
            $this->entityManager->remove($pending);
        }
    }

    public function summarize(Run $run, \DateTimeImmutable $closedAt): Summary
    {
        $stats = $this->attempts->statsOfRun($run);
        $played = $stats['right'] + $stats['wrong'] + $stats['late'];
        $mate = $run->getConfig()['mate'] ?? null;
        $moves = $run->getConfig()['moves'] ?? null;
        $ending = $run->getConfig()['ending'] ?? null;
        $count = $run->getConfig()['count'] ?? null;
        $lives = $run->getConfig()['lives'] ?? null;
        $streak = $run->getConfig()['streak'] ?? null;
        $failures = $stats['wrong'] + $stats['late'];
        $streakMetrics = [];
        if (\is_int($streak)) {
            // Not reached (stopped before): as many failures as lives, a defeat for the path.
            $won = $stats['bestStreak'] >= $streak;
            $streakMetrics = [
                'streak' => $streak,
                'bestStreak' => $stats['bestStreak'],
                'won' => $won,
                'failures' => $won ? $failures : max($failures, \is_int($lives) ? $lives : 1),
            ];
        }

        return new Summary(
            durationMs: max(0, (int) round(((float) $closedAt->format('U.u') - (float) $run->getStartedAt()->format('U.u')) * 1000)),
            itemCount: $played,
            successCount: $stats['right'],
            metrics: [
                'mate' => \is_int($mate) ? $mate : null,
                'kind' => \is_string($run->getConfig()['kind'] ?? null) ? $run->getConfig()['kind'] : null,
                'moves' => \is_int($moves) || \is_array($moves) ? $moves : null,
                'ending' => \is_string($ending) ? $ending : null,
                'count' => \is_int($count) ? $count : null,
                'lives' => \is_int($lives) ? $lives : null,
                'right' => $stats['right'],
                'wrong' => $stats['wrong'],
                'late' => $stats['late'],
                'activeMs' => $stats['activeMs'],
                'averageMs' => $played > 0 ? intdiv($stats['activeMs'], $played) : null,
            ] + $streakMetrics,
        );
    }

    public function review(Run $run): array
    {
        return array_map(static fn (Attempt $attempt): ReviewItem => new ReviewItem(
            self::ITEM_TYPE,
            AttemptStatus::Right === $attempt->getStatus() ? ReviewItem::OK : ReviewItem::FAIL,
            $attempt->getDurationMs(),
            [
                'kind' => $attempt->isQuestion() ? 'question' : $attempt->getKind()->value,
                'ask' => $attempt->getAsk(),
                'target' => $attempt->getTarget(),
                'moves' => $attempt->getMoves(),
                'ending' => $attempt->getEnding()->value,
                'status' => $attempt->getStatus()->value,
                'fen' => $attempt->getFen(),
                'expected' => $attempt->isQuestion() ? $attempt->getExpected() : self::sans($attempt->getFen(), $attempt->getExpected()),
                'answer' => match (true) {
                    $attempt->isQuestion() => $attempt->getAnswer() ?? [],
                    // 3.1: the player's move, then the reply chosen.
                    Kind::Reply === $attempt->getKind() => self::sans($attempt->getFen(), [$attempt->getExpected()[0] ?? '', ...($attempt->getAnswer() ?? [])]),
                    default => self::sans($attempt->getFen(), $attempt->getAnswer() ?? []),
                },
                'second' => null === $attempt->getSecondLine() ? null : self::sans($attempt->getFen(), $attempt->getSecondLine()),
                'mistakeAt' => $attempt->getMistakeAt(),
            ],
        ), $this->attempts->findResolvedOfRun($run));
    }

    /**
     * @throws ItemNotFoundException
     * @throws ItemAlreadySubmittedException
     */
    private function pendingAttempt(Run $run, string $itemId): Attempt
    {
        $attempt = Uuid::isValid($itemId) ? $this->attempts->findOwned(Uuid::fromString($itemId), $run->getUser()) : null;
        if (null === $attempt || !$attempt->getRun()->getId()->equals($run->getId())) {
            throw new ItemNotFoundException();
        }
        if (!$attempt->isPending()) {
            throw new ItemAlreadySubmittedException();
        }

        return $attempt;
    }

    /**
     * A new line for the run, below the player's puzzle rating: of the lesson's spec, or for the
     * Métronome one of its mates for the lines right so far (a shorter one when none is left), at
     * its tempo.
     *
     * @throws SubjectUnavailableException no mate fits
     */
    private function serve(Run $run, \DateTimeImmutable $now): Attempt
    {
        $config = self::check($run->getConfig());
        // A lesson of questions: the line just played, then its question.
        $last = $config['question'] ? \array_slice($this->attempts->findResolvedOfRun($run), -1)[0] ?? null : null;
        if (null !== $last && !$last->isQuestion()) {
            try {
                $drawn = QuestionDrawer::draw($last->getFen(), $last->getExpected());
            } catch (InvalidPositionException) {
                throw new \LogicException('A calculation line with an invalid solution.');
            }
            $question = Attempt::question($last, $drawn->ask, $drawn->target, $drawn->fen, $drawn->expected, $now);
            $this->entityManager->persist($question);
            $this->entityManager->flush();

            return $question;
        }
        $rating = $this->ratings->findOneByUser($run->getUser())?->getRating() ?? RatingCalculator::DEFAULT_RATING;
        $target = max(0, (int) round($rating) - CalculationRules::RATING_GAP);
        $think = $config['solve'] ? CalculationRules::SOLVE_SECONDS : CalculationRules::THINK_SECONDS;
        if (null === $config['spec']) {
            $right = $this->attempts->statsOfRun($run)['right'];
            $think = CalculationRules::tempo($right);
            $mates = CalculationRules::streakMates($right);
            $first = $mates[random_int(0, \count($mates) - 1)];
            $line = null;
            foreach ([$first, ...($first > 1 ? range($first - 1, 1) : []), ...($first < CalculationRules::MAX_MATE ? range($first + 1, CalculationRules::MAX_MATE) : [])] as $mate) {
                $line = $this->picker->pick($run, LineSpec::mate($mate), $target);
                if (null !== $line) {
                    break;
                }
            }
        } else {
            $line = $this->picker->pick($run, $config['spec'], $target);
        }
        if (null === $line) {
            throw new SubjectUnavailableException(CloseReason::SubjectUnavailable, ['reason' => 'no_puzzle'], 'No line to draw.');
        }
        $attempt = new Attempt($run, $line->puzzleId, $line->rating, $line->moves, $line->ending, $line->fen, $line->expected, $think, $now, $config['kind']);
        $this->entityManager->persist($attempt);
        $this->entityManager->flush();

        return $attempt;
    }

    private function item(Run $run, Attempt $attempt): Item
    {
        $config = self::check($run->getConfig());
        $stats = $this->attempts->statsOfRun($run);
        $fen = $attempt->getFen();

        if ($attempt->isQuestion()) {
            // Answered on an empty board: the position reached stays on the server.
            return new Item($attempt->getId()->toRfc4122(), self::ITEM_TYPE, [
                'kind' => 'question',
                'ask' => $attempt->getAsk(),
                'target' => $attempt->getTarget(),
                'color' => 'b' === (explode(' ', $fen)[1] ?? 'w') ? 'white' : 'black',
                'moves' => $attempt->getMoves(),
                'position' => $stats['right'] + $stats['wrong'] + $stats['late'] + 1,
                'count' => $config['count'],
                'lives' => null === $config['lives'] ? null : max(0, $config['lives'] - $stats['wrong'] - $stats['late']),
                'startedAt' => $attempt->getStartedAt()->format(\DATE_ATOM),
            ]);
        }

        $kind = $attempt->getKind();

        return new Item($attempt->getId()->toRfc4122(), self::ITEM_TYPE, [
            'kind' => $kind->value,
            'question' => $config['question'],
            'solve' => $config['solve'],
            // To solve it first, the opponent answering (the mini-boss): as a puzzle gives it. For
            // the engine in the browser (chapter 3): a line on it needs no analysis, 3.1 offers its
            // reply.
            'solution' => $config['solve'] || $kind->isEngine() ? $attempt->getExpected() : null,
            'engine' => $kind->isEngine(),
            'toleranceCp' => $kind->isEngine() ? CalculationRules::ENGINE_TOLERANCE_CP : null,
            'decoyGapCp' => \in_array($kind, [Kind::Reply, Kind::Combo], true) ? CalculationRules::DECOY_GAP_CP : null,
            'replies' => Kind::TwoReplies === $kind ? CalculationRules::TWO_REPLIES : null,
            'combos' => Kind::Combo === $kind ? CalculationRules::COMBOS : null,
            'fen' => $fen,
            'color' => 'b' === (explode(' ', $fen)[1] ?? 'w') ? 'black' : 'white',
            // The lesson's level (the line's length is no secret there); the Métronome hides it.
            // Its ending only when the lesson fixes it (a sacrifice may mate or win material).
            'mate' => Ending::Mate === $config['spec']?->ending ? $attempt->getMoves() : null,
            'moves' => null === $config['streak'] ? $attempt->getMoves() : null,
            'ending' => $config['spec']?->ending?->value,
            'capture' => $config['capture'],
            'thinkSeconds' => $attempt->getThinkSeconds(),
            'streak' => $stats['streak'],
            'streakTarget' => $config['streak'],
            'right' => $stats['right'],
            'plySeconds' => CalculationRules::PLY_SECONDS,
            'position' => $stats['right'] + $stats['wrong'] + $stats['late'] + 1,
            'count' => $config['count'],
            'lives' => null === $config['lives'] ? null : max(0, $config['lives'] - $stats['wrong'] - $stats['late']),
            'startedAt' => $attempt->getStartedAt()->format(\DATE_ATOM),
            'readyAt' => $attempt->getReadyAt()?->format(\DATE_ATOM),
        ]);
    }

    private static function thinkingEnd(Attempt $attempt): \DateTimeImmutable
    {
        return $attempt->getStartedAt()->modify(\sprintf('+%d seconds', $attempt->getThinkSeconds()));
    }

    /**
     * Whether a line comes after its time, on the server's clock: PLY_SECONDS per half-move of the
     * solution from « Je réponds » (or from the end of the thinking time, without it), for each of
     * its $lines (3.2: one against each reply). The instants
     * are stored to the second (truncated): one more second, and the network's tolerance, always in
     * the player's favour.
     */
    private static function isLate(Attempt $attempt, \DateTimeImmutable $at, int $lines = 1): bool
    {
        $readyAt = min($attempt->getReadyAt() ?? self::thinkingEnd($attempt), self::thinkingEnd($attempt));
        $deadlineMs = ((int) $readyAt->format('U') + 1 + CalculationRules::PLY_SECONDS * \count($attempt->getExpected()) * $lines) * 1000 + TimeboxRunner::SUBMISSION_TOLERANCE_MS;

        return (int) round((float) $at->format('U.u') * 1000) > $deadlineMs;
    }

    /**
     * Moves replayed from a position with their SAN (English letters); stops at the first one that
     * does not replay.
     *
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
     * @return Config
     *
     * @throws InvalidRunConfigException
     */
    private static function check(array $config): array
    {
        if ([] !== array_diff(array_keys($config), ['mate', 'moves', 'ending', 'themes', 'capture', 'question', 'solve', 'count', 'lives', 'streak', 'kind'])) {
            throw new InvalidRunConfigException('Unknown option: only mate, moves, ending, themes, capture, question, solve, count, lives, streak and kind are accepted.');
        }
        $kind = $config['kind'] ?? Kind::Line->value;
        $kind = \is_string($kind) ? Kind::tryFrom($kind) : null;
        if (null === $kind || ($kind->isEngine() && [] !== array_intersect(array_keys($config), ['mate', 'question', 'solve', 'streak']))) {
            throw new InvalidRunConfigException('kind must be line, reply, two_replies, defence, intermezzo or combo; chapter 3\'s kinds without mate, question, solve nor streak.');
        }
        $solve = $config['solve'] ?? false;
        if (!\is_bool($solve) || ($solve && (isset($config['streak']) || true === ($config['question'] ?? false)))) {
            throw new InvalidRunConfigException('solve must be true or false, without streak nor question.');
        }
        $question = $config['question'] ?? false;
        if (!\is_bool($question) || ($question && (isset($config['streak']) || !isset($config['count'])))) {
            throw new InvalidRunConfigException('question must be true or false, with a count and no streak.');
        }
        $streak = self::limit($config, 'streak', CalculationRules::MAX_STREAK);
        $capture = $config['capture'] ?? false;
        if (!\is_bool($capture)) {
            throw new InvalidRunConfigException('capture must be true or false.');
        }
        if (null !== $streak) {
            // The Métronome: its own mates, its lives.
            if ([] !== array_intersect(array_keys($config), ['mate', 'moves', 'ending', 'themes', 'capture', 'question', 'solve', 'count', 'kind']) || !isset($config['lives'])) {
                throw new InvalidRunConfigException('streak: with lives, without mate, moves, ending, themes, capture nor count.');
            }
            $spec = null;
        } else {
            $spec = self::spec($config, $capture, $kind);
        }

        return [
            'spec' => $spec,
            'kind' => $kind,
            'capture' => $capture,
            'question' => $question,
            'solve' => $solve,
            'count' => self::limit($config, 'count', CalculationRules::MAX_COUNT),
            'lives' => self::limit($config, 'lives', CalculationRules::MAX_LIVES),
            'streak' => $streak,
        ];
    }

    /**
     * The line a lesson draws: `mate` (a mate in so many moves), or `moves` with `ending` and
     * `themes`.
     *
     * @param array<string, mixed> $config
     *
     * @throws InvalidRunConfigException
     */
    private static function spec(array $config, bool $capture, Kind $kind): LineSpec
    {
        $mate = $config['mate'] ?? null;
        if (null !== $mate) {
            if (!self::isMoves($mate) || isset($config['moves']) || isset($config['ending']) || isset($config['themes'])) {
                throw new InvalidRunConfigException(\sprintf('mate must be a whole number from 1 to %d, without moves, ending nor themes.', CalculationRules::MAX_MATE));
            }

            return LineSpec::mate($mate, $capture);
        }
        $given = $config['moves'] ?? null;
        $given = \is_int($given) ? [$given] : $given;
        $moves = [];
        foreach (\is_array($given) && \count($given) <= CalculationRules::MAX_MATE ? $given : [null] as $n) {
            if (!self::isMoves($n)) {
                throw new InvalidRunConfigException(\sprintf('moves must be a whole number from 1 to %d, or a list of them.', CalculationRules::MAX_MATE));
            }
            if (!\in_array($n, $moves, true)) {
                $moves[] = $n;
            }
        }
        if ([] === $moves) {
            throw new InvalidRunConfigException('moves must not be empty.');
        }
        $ending = $config['ending'] ?? null;
        if (null !== $ending && (!\is_string($ending) || null === Ending::tryFrom($ending))) {
            throw new InvalidRunConfigException('ending must be mate or material.');
        }
        $given = $config['themes'] ?? [];
        $themes = [];
        foreach (\is_array($given) && \count($given) <= CalculationRules::MAX_THEMES ? $given : [null] as $theme) {
            if (!\is_string($theme) || 1 !== preg_match('/^[A-Za-z0-9]{1,32}$/', $theme)) {
                throw new InvalidRunConfigException(\sprintf('themes must be a list of at most %d theme keys.', CalculationRules::MAX_THEMES));
            }
            $themes[] = $theme;
        }

        return new LineSpec($moves, null === $ending ? null : Ending::from($ending), $themes, $capture, $kind);
    }

    /**
     * A line's length in the player's moves: 1 to MAX_MATE.
     *
     * @phpstan-assert-if-true int $value
     */
    private static function isMoves(mixed $value): bool
    {
        return \is_int($value) && $value >= 1 && $value <= CalculationRules::MAX_MATE;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws InvalidRunConfigException
     */
    private static function limit(array $config, string $key, int $max): ?int
    {
        $value = $config[$key] ?? null;
        if (null !== $value && (!\is_int($value) || $value < 1 || $value > $max)) {
            throw new InvalidRunConfigException(\sprintf('%s must be a whole number from 1 to %d.', $key, $max));
        }

        return $value;
    }
}
