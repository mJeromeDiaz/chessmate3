<?php

declare(strict_types=1);

namespace App\Notation\Training;

use App\Activity\Event\ExerciseCompleted;
use App\Activity\EventPublisher;
use App\Chess\InvalidPositionException;
use App\Chess\Rules;
use App\Entity\Notation\Attempt;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Activity\ExerciseType;
use App\Enum\Notation\AttemptStatus;
use App\Enum\Notation\Kind;
use App\Enum\Training\CloseReason;
use App\Enum\Training\Module;
use App\Notation\Drawing\LinePicker;
use App\Notation\Drawing\PuzzlePicker;
use App\Notation\Games\GamePicker;
use App\Notation\Games\LegendaryGames;
use App\Notation\Judge\WrittenLine;
use App\Notation\NotationRules;
use App\Puzzle\Catalog\PuzzleCatalog;
use App\Repository\Notation\AttemptRepository;
use App\Repository\Repertoire\RepertoireRepository;
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
use App\Training\Module\ReviewableModuleInterface;
use App\Training\Module\ReviewItem;
use App\Training\Module\Summary;
use App\Training\Module\TimeboxedModuleInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The notation module in timed runs (docs/NOTATION.md). The subject is the user; config `kind`
 * (play: a move announced, to play; sequence: moves announced, to play for both sides; write: a
 * puzzle to solve in one's head and write; line: a line of one's repertoire to complete, from
 * `repertoireId` or any repertoire of LINE_MIN_SEGMENTS segments; game_write and game_play: a
 * legendary game, `gameId` or any, 6 half-moves at a time to write once seen, or to play once read)
 * and, for a lesson of the mind map,
 * `count` (the run ends at its count of items, {@see CountedModuleInterface}). Items are drawn from
 * the puzzles or the repertoires, one at a time; the server judges each answer (moves played in UCI,
 * half-moves written in SAN). Never rated.
 *
 * @phpstan-type SanMove array{uci: string, san: string}
 */
final class NotationModule implements TimeboxedModuleInterface, ReviewableModuleInterface, CountedModuleInterface
{
    public const SUBJECT_TYPE = 'notation_player';
    public const ITEM_TYPE = 'notation';
    public const SOURCE_TYPE = 'notation_attempt';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AttemptRepository $attempts,
        private readonly PuzzlePicker $picker,
        private readonly LinePicker $lines,
        private readonly GamePicker $gamePicker,
        private readonly LegendaryGames $games,
        private readonly RepertoireRepository $repertoires,
        private readonly PuzzleCatalog $catalog,
        private readonly EventPublisher $events,
    ) {
    }

    public function module(): Module
    {
        return Module::Notation;
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
        // A session step is timed: no count.
        if ([] !== array_diff(array_keys($settings), ['kind', 'repertoireId', 'gameId'])) {
            throw new InvalidRunConfigException('Unknown option: only kind, repertoireId and gameId are accepted.');
        }
        [$kind, , $repertoireId, $gameId] = self::check($settings);
        $config = ['kind' => $kind->value];
        if (null !== $repertoireId) {
            $this->assertOwned($user, $repertoireId);
            $config['repertoireId'] = $repertoireId->toRfc4122();
        }
        if (null !== $gameId) {
            $this->assertGame($gameId);
            $config['gameId'] = $gameId;
        }

        return new PreparedStep($user->getId(), $config);
    }

    public function start(Run $run, \DateTimeImmutable $now): void
    {
        if (!$run->getSubjectId()->equals($run->getUser()->getId())) {
            throw new SubjectNotFoundException();
        }
        [, , $repertoireId, $gameId] = self::check($run->getConfig());
        if (null !== $repertoireId) {
            $this->assertOwned($run->getUser(), $repertoireId);
        }
        if (null !== $gameId) {
            $this->assertGame($gameId);
        }
        // Served now: nothing to draw refuses the run instead of opening an empty one.
        $this->serve($run, $now);
    }

    public function next(Run $run, \DateTimeImmutable $now): Item
    {
        return $this->item($run, $this->attempts->findPendingOfRun($run) ?? $this->serve($run, $now));
    }

    public function submit(Run $run, ItemSubmission $submission, \DateTimeImmutable $now): ItemResult
    {
        $attempt = Uuid::isValid($submission->itemId) ? $this->attempts->findOwned(Uuid::fromString($submission->itemId), $run->getUser()) : null;
        if (null === $attempt || !$attempt->getRun()->getId()->equals($run->getId())) {
            throw new ItemNotFoundException();
        }
        if (!$attempt->isPending()) {
            throw new ItemAlreadySubmittedException();
        }
        $expected = $attempt->getExpected();
        $reason = null;
        if (self::isWritten($attempt->getKind())) {
            if (\count(WrittenLine::tokens($submission->written)) > NotationRules::MAX_WRITTEN) {
                throw new InvalidItemSubmissionException('Too many half-moves written.');
            }
            try {
                $judged = WrittenLine::judge($attempt->getFen(), $submission->written, $expected);
            } catch (InvalidPositionException) {
                throw new \LogicException('A notation item with an invalid position.');
            }
            [$right, $mistakeAt, $reason, $answer] = [$judged['correct'], $judged['mistakeAt'], $judged['reason'], $judged['sans']];
        } else {
            $answer = $submission->moves;
            if ([] === $answer || (Kind::Play === $attempt->getKind() && \count($answer) > 1) || \count($answer) > \count($expected)) {
                throw new InvalidItemSubmissionException(Kind::Play === $attempt->getKind() ? 'One move is expected.' : 'More moves than the sequence has.');
            }
            $mistakeAt = self::firstDifference($answer, $expected);
            $right = null === $mistakeAt;
        }
        $attempt->resolve($right, $answer, $mistakeAt, $now);
        $this->entityManager->flush();

        $puzzleId = $attempt->getPuzzleId();
        $this->events->publish(new ExerciseCompleted(
            userId: $run->getUser()->getId()->toRfc4122(),
            type: ExerciseType::Notation,
            success: $right,
            durationMs: $attempt->getDurationMs() ?? 0,
            itemCount: 1,
            sourceType: self::SOURCE_TYPE,
            sourceId: $attempt->getId()->toRfc4122(),
            occurredAt: $now,
            metadata: [
                'kind' => $attempt->getKind()->value,
                'status' => $attempt->getStatus()->value,
                'puzzleId' => null === $puzzleId ? null : $this->catalog->get($puzzleId)->getLichessId(),
                'trainingRunId' => $run->getId()->toRfc4122(),
            ],
        ));

        $count = self::count($run);
        $resolved = $this->resolvedCount($run);
        $done = null !== $count && $resolved >= $count;

        return new ItemResult(
            itemId: $attempt->getId()->toRfc4122(),
            success: $right,
            data: [
                'status' => $attempt->getStatus()->value,
                'expected' => self::sans($attempt->getFen(), $expected),
                'answer' => self::isWritten($attempt->getKind()) ? $answer : self::sans($attempt->getFen(), $answer),
                'mistakeAt' => $attempt->getMistakeAt(),
                'reason' => $reason,
                'position' => $resolved,
                'count' => $count,
                'durationMs' => $attempt->getDurationMs(),
            ],
            closes: $done ? CloseReason::SubjectFinished : null,
            context: $done ? ['reason' => 'count_done'] : [],
        );
    }

    public function close(Run $run, CloseReason $reason, \DateTimeImmutable $now): void
    {
        // The item on screen at the end is not counted.
        $pending = $this->attempts->findPendingOfRun($run);
        if (null !== $pending) {
            $this->entityManager->remove($pending);
        }
    }

    public function summarize(Run $run, \DateTimeImmutable $closedAt): Summary
    {
        $stats = $this->attempts->statsOfRun($run);
        $played = $stats['right'] + $stats['wrong'];
        $kind = $run->getConfig()['kind'] ?? null;

        return new Summary(
            durationMs: max(0, (int) round(((float) $closedAt->format('U.u') - (float) $run->getStartedAt()->format('U.u')) * 1000)),
            itemCount: $played,
            successCount: $stats['right'],
            metrics: [
                'kind' => \is_string($kind) ? $kind : null,
                'count' => self::count($run),
                'right' => $stats['right'],
                'wrong' => $stats['wrong'],
                'activeMs' => $stats['activeMs'],
                'averageMs' => $played > 0 ? intdiv($stats['activeMs'], $played) : null,
            ],
        );
    }

    public function review(Run $run): array
    {
        return array_map(static fn (Attempt $attempt): ReviewItem => new ReviewItem(
            self::ITEM_TYPE,
            AttemptStatus::Right === $attempt->getStatus() ? ReviewItem::OK : ReviewItem::FAIL,
            $attempt->getDurationMs(),
            [
                'kind' => $attempt->getKind()->value,
                'fen' => $attempt->getFen(),
                'expected' => self::sans($attempt->getFen(), $attempt->getExpected()),
                'answer' => self::isWritten($attempt->getKind()) ? ($attempt->getAnswer() ?? []) : self::sans($attempt->getFen(), $attempt->getAnswer() ?? []),
                'mistakeAt' => $attempt->getMistakeAt(),
                'shown' => self::sans(Rules::INITIAL_FEN, $attempt->getShown()),
                'label' => $attempt->getLabel(),
            ],
        ), $this->attempts->findResolvedOfRun($run));
    }

    /**
     * A new item for the run, drawn from the puzzles.
     *
     * @throws SubjectUnavailableException no puzzle fits
     */
    private function serve(Run $run, \DateTimeImmutable $now): Attempt
    {
        [$kind, , $repertoireId, $gameId] = self::check($run->getConfig());
        if ($kind->isGame()) {
            $exercise = $this->gamePicker->pick($run, $gameId);
            if (null === $exercise) {
                throw new SubjectUnavailableException(CloseReason::SubjectUnavailable, ['reason' => 'no_game'], 'No legendary game to play (no_game).');
            }
        } elseif (Kind::Line === $kind) {
            $exercise = $this->lines->pick($run, $repertoireId);
            if (null === $exercise) {
                throw new SubjectUnavailableException(CloseReason::SubjectUnavailable, ['reason' => 'no_repertoire'], \sprintf('No repertoire of %d segments with a line to complete (no_repertoire).', NotationRules::LINE_MIN_SEGMENTS));
            }
        } else {
            $exercise = $this->picker->pick($run, $kind);
            if (null === $exercise) {
                throw new SubjectUnavailableException(CloseReason::SubjectUnavailable, ['reason' => 'no_puzzle'], 'No puzzle to draw.');
            }
        }
        $attempt = new Attempt($run, $kind, $exercise->fen, $exercise->expected, $exercise->puzzleId, $now);
        if (null !== $exercise->lineKey) {
            $attempt->fromLine($exercise->shown, $exercise->lineKey, $exercise->label, $exercise->orientation);
        }
        $this->entityManager->persist($attempt);
        $this->entityManager->flush();

        return $attempt;
    }

    private function item(Run $run, Attempt $attempt): Item
    {
        $fen = $attempt->getFen();
        $expected = self::sans($fen, $attempt->getExpected());
        $data = [
            'kind' => $attempt->getKind()->value,
            'fen' => $fen,
            'color' => 'b' === (explode(' ', $fen)[1] ?? 'w') ? 'black' : 'white',
            'position' => $this->resolvedCount($run) + 1,
            'count' => self::count($run),
            'startedAt' => $attempt->getStartedAt()->format(\DATE_ATOM),
        ];
        $data += match ($attempt->getKind()) {
            // The move to play, and the whole sequence: the client judges each move at once (the
            // server judges again).
            Kind::Play => ['announced' => $expected[0] ?? null],
            Kind::Sequence => ['moves' => $expected],
            // Only how many half-moves to write.
            Kind::Write => ['plies' => \count($expected)],
            // A legendary game: the moves of this chunk (shown played, then hidden, to write; or read,
            // to play), the game so far and its players.
            Kind::GameWrite, Kind::GamePlay => [
                'plies' => \count($expected),
                'moves' => $expected,
                'shown' => self::sans(Rules::INITIAL_FEN, $attempt->getShown()),
                'label' => $attempt->getLabel(),
                'orientation' => $attempt->getOrientation() ?? 'white',
            ],
            // The line played so far, from the initial position, and how many half-moves to write.
            Kind::Line => [
                'plies' => \count($expected),
                'shown' => self::sans(Rules::INITIAL_FEN, $attempt->getShown()),
                'label' => $attempt->getLabel(),
                'orientation' => $attempt->getOrientation() ?? 'white',
            ],
        };

        return new Item($attempt->getId()->toRfc4122(), self::ITEM_TYPE, $data);
    }

    /** Answered by writing (a puzzle's line, a repertoire line's end, a legendary game's moves seen), not by playing. */
    private static function isWritten(Kind $kind): bool
    {
        return Kind::Write === $kind || Kind::Line === $kind || Kind::GameWrite === $kind;
    }

    /**
     * @throws InvalidRunConfigException no such legendary game
     */
    private function assertGame(int $gameId): void
    {
        if (null === $this->games->find($gameId)) {
            throw new InvalidRunConfigException(\sprintf('No legendary game %d.', $gameId));
        }
    }

    /**
     * @throws SubjectNotFoundException another player's repertoire, or none
     */
    private function assertOwned(User $user, Uuid $repertoireId): void
    {
        if (null === $this->repertoires->findOwned($repertoireId, $user)) {
            throw new SubjectNotFoundException();
        }
    }

    private function resolvedCount(Run $run): int
    {
        $stats = $this->attempts->statsOfRun($run);

        return $stats['right'] + $stats['wrong'];
    }

    /**
     * The first move played that is not the expected one (or the end of a sequence cut short),
     * null when all are right.
     *
     * @param list<string> $played
     * @param list<string> $expected
     */
    private static function firstDifference(array $played, array $expected): ?int
    {
        foreach ($expected as $i => $uci) {
            if (($played[$i] ?? null) !== $uci) {
                return $i;
            }
        }

        return null;
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

    private static function count(Run $run): ?int
    {
        $count = $run->getConfig()['count'] ?? null;

        return \is_int($count) ? $count : null;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{Kind, int|null, Uuid|null, int|null}
     *
     * @throws InvalidRunConfigException
     */
    private static function check(array $config): array
    {
        if ([] !== array_diff(array_keys($config), ['kind', 'count', 'repertoireId', 'gameId'])) {
            throw new InvalidRunConfigException('Unknown option: only kind, count, repertoireId and gameId are accepted.');
        }
        $kind = \is_string($config['kind'] ?? null) ? Kind::tryFrom($config['kind']) : null;
        if (null === $kind) {
            throw new InvalidRunConfigException(\sprintf('kind must be one of %s.', implode(', ', Kind::values())));
        }
        $count = $config['count'] ?? null;
        if (null !== $count && (!\is_int($count) || $count < 1 || $count > NotationRules::MAX_COUNT)) {
            throw new InvalidRunConfigException(\sprintf('count must be a whole number from 1 to %d.', NotationRules::MAX_COUNT));
        }
        $gameId = $config['gameId'] ?? null;
        if (null !== $gameId) {
            if (!$kind->isGame() || !\is_int($gameId) || $gameId < 1) {
                throw new InvalidRunConfigException('gameId: a legendary game\'s id, for the game exercises only.');
            }
        }
        $repertoireId = $config['repertoireId'] ?? null;
        if (null === $repertoireId) {
            return [$kind, $count, null, $gameId];
        }
        if (Kind::Line !== $kind || !\is_string($repertoireId) || !Uuid::isValid($repertoireId)) {
            throw new InvalidRunConfigException('repertoireId: a repertoire\'s id, for the line exercise only.');
        }

        return [$kind, $count, Uuid::fromString($repertoireId), $gameId];
    }
}
