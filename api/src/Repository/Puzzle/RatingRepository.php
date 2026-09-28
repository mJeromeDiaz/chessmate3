<?php

declare(strict_types=1);

namespace App\Repository\Puzzle;

use App\Entity\Puzzle\Rating;
use App\Entity\User;
use App\Puzzle\Rating\RatingCalculator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Rating>
 */
class RatingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Rating::class);
    }

    public function findOneByUser(User $user): ?Rating
    {
        return $this->find($user->getId());
    }

    /**
     * Returns the user's rating row, created with the default values if missing, locked
     * (SELECT ... FOR UPDATE) until the surrounding transaction ends: this serialises everything a
     * user does with puzzles. Must be called inside a transaction.
     */
    public function lockForUser(User $user): Rating
    {
        $initial = RatingCalculator::initial();
        // INSERT IGNORE: two first requests in parallel must not fail on the primary key.
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT IGNORE INTO puzzle_rating (user_id, rating, deviation, volatility, rated_count, source, updated_at)
             VALUES (:user, :rating, :deviation, :volatility, 0, :source, :now)',
            [
                'user' => $user->getId()->toBinary(),
                'rating' => $initial->rating,
                'deviation' => $initial->deviation,
                'volatility' => $initial->volatility,
                'source' => 'default',
                'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );

        $rating = $this->find($user->getId());
        if (null === $rating) {
            throw new \LogicException('Puzzle rating row missing after insert.');
        }
        // refresh() with a lock mode re-reads the row under FOR UPDATE, so a copy already in the
        // identity map can never be stale.
        $this->getEntityManager()->refresh($rating, LockMode::PESSIMISTIC_WRITE);

        return $rating;
    }
}
