<?php

declare(strict_types=1);

namespace App\Woodpecker\Set;

use App\Entity\User;
use App\Entity\Woodpecker\Set;
use App\Enum\Woodpecker\CycleStatus;
use App\Repository\Puzzle\RatingRepository;
use App\Repository\Puzzle\ThemeRepository;
use App\Repository\Woodpecker\CycleRepository;
use App\Repository\Woodpecker\SetPuzzleRepository;
use App\Repository\Woodpecker\SetRepository;
use App\Woodpecker\Cycle\CycleRunner;
use App\Woodpecker\Exception\NotEnoughPuzzlesException;
use App\Woodpecker\Exception\OngoingSetExistsException;
use App\Woodpecker\Exception\SetNotFoundException;
use App\Woodpecker\Schedule\DeadlineCalculator;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Uid\Uuid;

/**
 * Creates sets and handles their life cycle (docs/WOODPECKER.md).
 */
final class SetManager
{
    /** Default difficulty: puzzles 150 to 450 points below the user's rating (the method wants them easy). */
    public const DEFAULT_BELOW_MAX = 450;
    public const DEFAULT_BELOW_MIN = 150;
    public const RATING_FLOOR = 400;
    public const RATING_CEILING = 3200;
    public const MIN_RANGE_WIDTH = 100;

    /**
     * @param iterable<CreationPolicyInterface> $policies
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SetRepository $sets,
        private readonly SetPuzzleRepository $setPuzzles,
        private readonly CycleRepository $cycles,
        private readonly ThemeRepository $themes,
        private readonly RatingRepository $ratings,
        private readonly SetGenerator $generator,
        private readonly CycleRunner $runner,
        private readonly ClockInterface $clock,
        #[AutowireIterator(CreationPolicyInterface::TAG)]
        private readonly iterable $policies = [],
    ) {
    }

    /**
     * The default range: [rating − 450, rating − 150], within [400, 3200], at least 100 wide.
     *
     * @return array{int, int}
     */
    public function defaultRatingRange(User $user): array
    {
        $rating = (int) round($this->ratings->findOneByUser($user)?->getRating() ?? 1500.0);
        $max = min(self::RATING_CEILING, max(self::RATING_FLOOR + self::MIN_RANGE_WIDTH, $rating - self::DEFAULT_BELOW_MIN));
        $min = max(self::RATING_FLOOR, min($max - self::MIN_RANGE_WIDTH, $rating - self::DEFAULT_BELOW_MAX));

        return [$min, $max];
    }

    /**
     * @throws OngoingSetExistsException
     * @throws NotEnoughPuzzlesException
     * @throws \InvalidArgumentException  unknown theme key
     */
    public function create(User $user, string $name, SetConfig $config): Set
    {
        foreach ($this->policies as $policy) {
            $policy->check($user, $config);
        }
        if (null !== $this->sets->findOngoing($user)) {
            throw new OngoingSetExistsException();
        }

        $themes = $this->themes->findByKeys($config->themes);
        if (\count($themes) !== \count(array_unique($config->themes))) {
            throw new \InvalidArgumentException('Unknown theme.');
        }
        // Outside the transaction: read-only index scans, no lock held meanwhile.
        $puzzleIds = $this->generator->generate($config, array_map(static fn ($theme): int => (int) $theme->getId(), $themes));

        try {
            return $this->entityManager->wrapInTransaction(function () use ($user, $name, $config, $puzzleIds): Set {
                $now = $this->now();
                $set = new Set($user, $name, $config, $now);
                $this->entityManager->persist($set);
                // The unique index on active_user_id rejects a concurrent second creation here.
                $this->entityManager->flush();
                $this->setPuzzles->insertList($set, $puzzleIds);
                $this->runner->openRun($set, 1, 1, $now, $now);

                return $set;
            });
        } catch (UniqueConstraintViolationException) {
            throw new OngoingSetExistsException();
        }
    }

    public function pause(User $user, Uuid $setId): Set
    {
        return $this->transition($user, $setId, function (Set $set, \DateTimeImmutable $now): void {
            // A run already past its deadline is lost before the pause, not saved by it.
            $this->runner->refresh($set, $now);
            $set->pause($now);
        });
    }

    /**
     * Resumes a paused set; the open run's dates move by the pause length (then to the end of a
     * local day).
     */
    public function resume(User $user, Uuid $setId): Set
    {
        return $this->transition($user, $setId, function (Set $set, \DateTimeImmutable $now): void {
            $pausedAt = $set->resume();
            $cycle = $this->cycles->findOpen($set);
            if (null === $cycle) {
                return;
            }
            $seconds = max(0, $now->getTimestamp() - $pausedAt->getTimestamp());
            $timezone = $set->getUser()->getDateTimeZone();
            $availableAt = CycleStatus::Resting === $cycle->getStatus()
                ? DeadlineCalculator::shift($cycle->getAvailableAt(), $seconds, $timezone)
                : $cycle->getAvailableAt();
            $cycle->reschedule($availableAt, DeadlineCalculator::shift($cycle->getDeadlineAt(), $seconds, $timezone));
            $this->runner->refresh($set, $now);
        });
    }

    public function abandon(User $user, Uuid $setId): Set
    {
        return $this->transition($user, $setId, static fn (Set $set, \DateTimeImmutable $now) => $set->abandon($now));
    }

    public function archive(User $user, Uuid $setId): Set
    {
        return $this->transition($user, $setId, static fn (Set $set, \DateTimeImmutable $now) => $set->archive($now));
    }

    /**
     * Reads a set with its time-driven transitions applied.
     */
    public function load(User $user, Uuid $setId): Set
    {
        return $this->transition($user, $setId, fn (Set $set, \DateTimeImmutable $now) => $this->runner->refresh($set, $now));
    }

    /**
     * @param callable(Set, \DateTimeImmutable): void $change
     *
     * @throws SetNotFoundException
     * @throws \DomainException     transition not allowed in the set's status
     */
    private function transition(User $user, Uuid $setId, callable $change): Set
    {
        return $this->entityManager->wrapInTransaction(function () use ($user, $setId, $change): Set {
            $set = $this->sets->lockOwned($setId, $user) ?? throw new SetNotFoundException();
            $change($set, $this->now());

            return $set;
        });
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
    }
}
