<?php

declare(strict_types=1);

namespace App\Puzzle\Rating;

use App\Entity\Puzzle\Rating;
use App\Entity\Puzzle\RatingChange;
use App\Entity\User;
use App\Enum\Puzzle\RatingChangeReason;
use App\Enum\Puzzle\RatingSource;
use App\Repository\Puzzle\RatingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Seeds the puzzle rating from a level the player declared (docs/ONBOARDING.md), as long as no
 * rated puzzle was played and the rating was not imported from Lichess: a later declaration
 * replaces an earlier one, never the rating play built.
 */
final readonly class DeclaredRatingSeeder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RatingRepository $ratings,
    ) {
    }

    public static function canSeed(?Rating $rating): bool
    {
        return null === $rating || (0 === $rating->getRatedCount() && RatingSource::Lichess !== $rating->getSource());
    }

    /**
     * Inside the caller's transaction (the rating row is locked).
     *
     * @return bool whether the rating was seeded
     */
    public function seed(User $user, int $declared, \DateTimeImmutable $now): bool
    {
        $rating = $this->ratings->lockForUser($user);
        if (!self::canSeed($rating)) {
            return false;
        }
        $before = $rating->getState();
        $after = RatingCalculator::declared($declared);
        if (RatingSource::Declared === $rating->getSource() && $before->rating === $after->rating) {
            // The same level again: nothing to record.
            return true;
        }
        $rating->seedFromDeclaration($after, $now);
        $this->entityManager->persist(new RatingChange($user, RatingChangeReason::LevelDeclared, $before, $after, $now));
        $this->entityManager->flush();

        return true;
    }
}
