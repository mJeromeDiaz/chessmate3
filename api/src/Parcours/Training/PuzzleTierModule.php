<?php

declare(strict_types=1);

namespace App\Parcours\Training;

use App\Activity\Event\ExerciseCompleted;
use App\Activity\EventPublisher;
use App\ApiResource\Puzzle\PuzzleView;
use App\Entity\Catalog\Puzzle;
use App\Entity\Parcours\Attempt;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Activity\ExerciseType;
use App\Enum\Parcours\AttemptStatus;
use App\Enum\Parcours\Effort;
use App\Enum\Parcours\Theme;
use App\Enum\Training\CloseReason;
use App\Enum\Training\Module;
use App\Parcours\ParcoursRules;
use App\Parcours\Selection\PuzzlePicker;
use App\Puzzle\Catalog\PuzzleCatalog;
use App\Puzzle\Rating\RatingCalculator;
use App\Puzzle\Selection\SelectionUnavailableException;
use App\Puzzle\Solution\InvalidSubmissionException;
use App\Puzzle\Solution\SolutionValidator;
use App\Repository\Parcours\AttemptRepository;
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
use App\Training\Module\ReviewableModuleInterface;
use App\Training\Module\ReviewItem;
use App\Training\Module\Summary;
use App\Training\Module\TimeboxedModuleInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A tier of puzzles of a parcours (docs/PARCOURS.md): `count` puzzles of the tier's `themes` (or of
 * an opening `family` with the player's `color`), the first third below the player's puzzle
 * `rating` (read when the tier starts), the middle at it, the last third above; the run closes
 * with its last puzzle. A miss shows the solution and the tier goes on; a hint or the solution is
 * a miss. Never rated. Only a step of a parcours (its session): its clock is only an expiry.
 */
final class PuzzleTierModule implements TimeboxedModuleInterface, ReviewableModuleInterface, CountedModuleInterface
{
    public const SUBJECT_TYPE = 'parcours_player';
    public const ITEM_TYPE = 'parcours_puzzle';
    public const SOURCE_TYPE = 'parcours_attempt';

    private const SETTINGS = ['tier', 'themes', 'family', 'color', 'count'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AttemptRepository $attempts,
        private readonly PuzzlePicker $picker,
        private readonly PuzzleCatalog $catalog,
        private readonly SolutionValidator $validator,
        private readonly RatingRepository $ratings,
        private readonly EventPublisher $events,
    ) {
    }

    public function module(): Module
    {
        return Module::ParcoursPuzzles;
    }

    public function subjectType(): string
    {
        return self::SUBJECT_TYPE;
    }

    public function isCounted(array $config): bool
    {
        return true;
    }

    /**
     * Settings of the parcours' step: `tier`, `themes` or `family` + `color`, `count`; the
     * player's puzzle rating is read now, when the tier starts.
     */
    public function prepare(User $user, array $settings, string $notes): PreparedStep
    {
        if ([] !== array_diff(array_keys($settings), self::SETTINGS)) {
            throw new InvalidRunConfigException(\sprintf('Unknown setting: only %s are accepted.', implode(', ', self::SETTINGS)));
        }
        $rating = $this->ratings->findOneByUser($user)?->getRating() ?? RatingCalculator::DEFAULT_RATING;
        $config = [...$settings, 'rating' => (int) round($rating)];
        self::check($config);

        return new PreparedStep($user->getId(), $config);
    }

    public function start(Run $run, \DateTimeImmutable $now): void
    {
        if (!$run->getSubjectId()->equals($run->getUser()->getId())) {
            throw new SubjectNotFoundException();
        }
        if (null === $run->getParentId()) {
            throw new InvalidRunConfigException('A parcours tier is played in its parcours.');
        }
        self::check($run->getConfig());
        // Served now: nothing to draw refuses the tier instead of opening an empty one.
        $this->serve($run, $now, CloseReason::SubjectUnavailable);
    }

    public function next(Run $run, \DateTimeImmutable $now): Item
    {
        $attempt = $this->attempts->findPendingOfRun($run) ?? $this->serve($run, $now, CloseReason::SubjectFinished);

        return $this->item($attempt);
    }

    public function submit(Run $run, ItemSubmission $submission, \DateTimeImmutable $now): ItemResult
    {
        $attempt = $this->pendingAttempt($run, $submission->itemId);
        $puzzle = $this->catalog->get($attempt->getPuzzleId());
        try {
            $replay = $this->validator->replay($puzzle, $submission->moves);
        } catch (InvalidSubmissionException $e) {
            throw new InvalidItemSubmissionException($e->getMessage(), 0, $e);
        }
        $helped = $submission->hintLevel > 0 || $submission->solutionShown;
        $solved = $replay->completed && 0 === $replay->mistakes && !$helped;
        $status = $solved ? AttemptStatus::Solved : AttemptStatus::Failed;
        $attempt->resolve($status, $submission->moves, $replay->mistakes, $helped, $now);
        $this->entityManager->flush();

        $config = self::check($run->getConfig());
        $this->events->publish(new ExerciseCompleted(
            userId: $run->getUser()->getId()->toRfc4122(),
            type: ExerciseType::ParcoursPuzzle,
            success: $solved,
            durationMs: $attempt->getDurationMs() ?? 0,
            itemCount: 1,
            sourceType: self::SOURCE_TYPE,
            sourceId: $attempt->getId()->toRfc4122(),
            occurredAt: $now,
            metadata: [
                'status' => $status->value,
                'puzzleId' => $puzzle->getLichessId(),
                'rating' => $attempt->getPuzzleRating(),
                'targetRating' => $attempt->getTargetRating(),
                'position' => $attempt->getPosition(),
                'tier' => $config['tier'],
                'helped' => $helped,
                'trainingRunId' => $run->getId()->toRfc4122(),
            ],
        ));

        $done = $this->attempts->countResolvedOfRun($run) >= $config['count'];

        return new ItemResult(
            itemId: $attempt->getId()->toRfc4122(),
            success: $solved,
            data: [
                'status' => $status->value,
                'mistakes' => $replay->mistakes,
                'durationMs' => $attempt->getDurationMs(),
                'position' => $attempt->getPosition(),
                'count' => $config['count'],
                'tierDone' => $done,
            ],
            closes: $done ? CloseReason::SubjectFinished : null,
            context: $done ? ['reason' => 'tier_done'] : [],
        );
    }

    public function close(Run $run, CloseReason $reason, \DateTimeImmutable $now): void
    {
        // The puzzle on screen at the end is not counted.
        $pending = $this->attempts->findPendingOfRun($run);
        if (null !== $pending) {
            $this->entityManager->remove($pending);
            $this->entityManager->flush();
        }
    }

    public function summarize(Run $run, \DateTimeImmutable $closedAt): Summary
    {
        $config = $run->getConfig();
        $solved = 0;
        $failed = 0;
        $activeMs = 0;
        $ratings = [];
        foreach ($this->attempts->findResolvedOfRun($run) as $attempt) {
            AttemptStatus::Solved === $attempt->getStatus() ? ++$solved : ++$failed;
            $activeMs += $attempt->getDurationMs() ?? 0;
            $ratings[] = $attempt->getPuzzleRating();
        }
        $played = $solved + $failed;

        return new Summary(
            durationMs: max(0, (int) round(((float) $closedAt->format('U.u') - (float) $run->getStartedAt()->format('U.u')) * 1000)),
            itemCount: $played,
            successCount: $solved,
            metrics: [
                'tier' => $config['tier'] ?? null,
                'themes' => $config['themes'] ?? [],
                'family' => $config['family'] ?? null,
                'count' => $config['count'] ?? null,
                'rating' => $config['rating'] ?? null,
                'solved' => $solved,
                'failed' => $failed,
                'hardestRating' => [] === $ratings ? null : max($ratings),
                'activeMs' => $activeMs,
                'averageMs' => $played > 0 ? intdiv($activeMs, $played) : null,
            ],
        );
    }

    public function review(Run $run): array
    {
        $attempts = $this->attempts->findResolvedOfRun($run);
        $puzzles = $this->catalog->byIds(array_map(static fn (Attempt $attempt): int => $attempt->getPuzzleId(), $attempts));

        return array_map(fn (Attempt $attempt): ReviewItem => new ReviewItem(
            self::ITEM_TYPE,
            AttemptStatus::Solved === $attempt->getStatus() ? ReviewItem::OK : ReviewItem::FAIL,
            $attempt->getDurationMs(),
            [
                'puzzle' => self::puzzle($puzzles[$attempt->getPuzzleId()] ?? $this->catalog->get($attempt->getPuzzleId())),
                'mistakes' => $attempt->getMistakes(),
                'helped' => $attempt->isHelped(),
                'position' => $attempt->getPosition(),
                'targetRating' => $attempt->getTargetRating(),
            ],
        ), $attempts);
    }

    /**
     * The run's item on screen.
     *
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
     * The tier's next puzzle.
     *
     * @param CloseReason $emptyReason how the run closes when nothing is left to draw
     *
     * @throws SubjectUnavailableException nothing left to draw, or the selection is being rebuilt
     */
    private function serve(Run $run, \DateTimeImmutable $now, CloseReason $emptyReason): Attempt
    {
        $config = self::check($run->getConfig());
        $position = $this->attempts->countResolvedOfRun($run);
        $target = ParcoursRules::target($config['rating'], $position, $config['count']);
        try {
            $puzzleId = $this->picker->pick($run, $config['themes'], $config['family'], $config['color'], $target);
        } catch (SelectionUnavailableException) {
            throw new SubjectUnavailableException(CloseReason::SubjectUnavailable, ['reason' => 'selection_unavailable'], 'The puzzle selection is being rebuilt.');
        }
        if (null === $puzzleId) {
            throw new SubjectUnavailableException($emptyReason, ['reason' => 'no_puzzle'], 'No puzzle left for this tier.');
        }
        $attempt = new Attempt($run, $puzzleId, $this->catalog->get($puzzleId)->getRating(), $position, $target, $now);
        $this->entityManager->persist($attempt);
        $this->entityManager->flush();

        return $attempt;
    }

    private function item(Attempt $attempt): Item
    {
        $config = self::check($attempt->getRun()->getConfig());

        return new Item($attempt->getId()->toRfc4122(), self::ITEM_TYPE, [
            'puzzle' => self::puzzle($this->catalog->get($attempt->getPuzzleId())),
            'tier' => $config['tier'],
            'themes' => $config['themes'],
            'family' => $config['family'],
            'position' => $attempt->getPosition(),
            'count' => $config['count'],
            'targetRating' => $attempt->getTargetRating(),
            'startedAt' => $attempt->getStartedAt()->format(\DATE_ATOM),
        ]);
    }

    /**
     * @return array{id: string, fen: string, moves: list<string>, playerColor: string, rating: int, themes: list<string>, gameUrl: string}
     */
    private static function puzzle(Puzzle $entity): array
    {
        $puzzle = PuzzleView::from($entity);

        return [
            'id' => $puzzle->id,
            'fen' => $puzzle->fen,
            'moves' => $puzzle->moves,
            'playerColor' => $puzzle->playerColor,
            'rating' => $puzzle->rating,
            'themes' => $puzzle->themes,
            'gameUrl' => $puzzle->gameUrl,
        ];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{tier: int, themes: list<string>, family: string|null, color: string|null, count: int, rating: int}
     *
     * @throws InvalidRunConfigException
     */
    private static function check(array $config): array
    {
        if ([] !== array_diff(array_keys($config), [...self::SETTINGS, 'rating'])) {
            throw new InvalidRunConfigException('Unknown option.');
        }
        $tier = $config['tier'] ?? null;
        if (!\is_int($tier) || $tier < 0 || $tier >= ParcoursRules::TIERS) {
            throw new InvalidRunConfigException(\sprintf('tier is 0 to %d.', ParcoursRules::TIERS - 1));
        }
        $count = $config['count'] ?? null;
        if (!\in_array($count, array_map(static fn (Effort $effort): int => $effort->perTier(), Effort::cases()), true)) {
            throw new InvalidRunConfigException('count is 3, 6 or 10.');
        }
        $rating = $config['rating'] ?? null;
        if (!\is_int($rating) || $rating < 0 || $rating > 4000) {
            throw new InvalidRunConfigException('rating is 0 to 4000.');
        }
        $family = $config['family'] ?? null;
        $color = $config['color'] ?? null;
        $themes = $config['themes'] ?? [];
        if (null !== $family) {
            if (!\is_string($family) || 1 !== preg_match('/^[A-Za-z0-9_-]{1,64}$/', $family) || !\in_array($color, ['w', 'b'], true) || [] !== $themes) {
                throw new InvalidRunConfigException('An opening tier has a family, a color (w or b) and no themes.');
            }

            return ['tier' => $tier, 'themes' => [], 'family' => $family, 'color' => $color, 'count' => $count, 'rating' => $rating];
        }
        if (null !== $color || !\is_array($themes) || [] === $themes || !array_is_list($themes)) {
            throw new InvalidRunConfigException('A tier has themes of the parcours, or an opening family.');
        }
        $keys = [];
        foreach ($themes as $key) {
            if (!\is_string($key) || !\in_array($key, self::themeKeys(), true)) {
                throw new InvalidRunConfigException('A tier has themes of the parcours, or an opening family.');
            }
            $keys[] = $key;
        }

        return ['tier' => $tier, 'themes' => $keys, 'family' => null, 'color' => null, 'count' => $count, 'rating' => $rating];
    }

    /**
     * The Lichess themes the parcours tiers draw from.
     *
     * @return list<string>
     */
    private static function themeKeys(): array
    {
        $keys = [];
        foreach (Theme::cases() as $theme) {
            for ($tier = 0; $tier < ParcoursRules::TIERS; ++$tier) {
                array_push($keys, ...ParcoursRules::tier($theme, $tier)['themes']);
            }
        }

        return $keys;
    }
}
