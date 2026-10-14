<?php

declare(strict_types=1);

namespace App\Puzzle\Pardon;

use App\Entity\Puzzle\Attempt;
use App\Entity\Puzzle\RatingChange;
use App\Entity\User;
use App\Enum\Puzzle\RatingChangeReason;
use App\Puzzle\Attempt\Exception\AttemptNotFoundException;
use App\Repository\Puzzle\AttemptRepository;
use App\Repository\Puzzle\RatingChangeRepository;
use App\Repository\Puzzle\RatingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The store's pardon on a rated puzzle just missed (docs/PUZZLES.md, "Erreur pardonnée"): the
 * rating goes back exactly to its state before the puzzle (rating, deviation, volatility, rated
 * count), a `pardon` step joins the history. Only while the miss is the user's last rating change
 * and no puzzle was served since (the miss sheet still on screen). The attempt stays missed (stats,
 * XP).
 *
 * Lock order as in AttemptService: the rating row, then the attempt.
 */
final readonly class Pardoner
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RatingRepository $ratings,
        private RatingChangeRepository $ratingChanges,
        private AttemptRepository $attempts,
        private PardonStockInterface $pardons,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{attempt: Attempt, pardons: int}
     *
     * @throws AttemptNotFoundException
     * @throws PardonRefusedException
     */
    public function pardon(User $user, Uuid $attemptId): array
    {
        $outcome = $this->entityManager->wrapInTransaction(function () use ($user, $attemptId): Attempt|PardonRefusedException {
            $now = $this->clock->now();
            $rating = $this->ratings->lockForUser($user);
            $attempt = $this->attempts->findOwnedForUpdate($attemptId, $user) ?? throw new AttemptNotFoundException();
            $change = $attempt->getRatingChange();
            if (!$attempt->isPardonable() || null === $change) {
                return new PardonRefusedException(PardonRefusedException::NOT_PARDONABLE);
            }
            if (!$change->getId()->equals($this->ratingChanges->findLatestOf($user)?->getId() ?? Uuid::v7())
                || $this->attempts->servedAfter($attempt)) {
                return new PardonRefusedException(PardonRefusedException::TOO_LATE);
            }
            if (!$this->pardons->consume($user->getId())) {
                return new PardonRefusedException(PardonRefusedException::NO_PARDON);
            }
            $missed = $rating->getState();
            $rating->restore($change->before(), $now);
            $this->entityManager->persist(new RatingChange($user, RatingChangeReason::Pardon, $missed, $change->before(), $now));
            $attempt->pardon();
            $this->entityManager->flush();

            return $attempt;
        });
        // Refused before any write: thrown outside, the entity manager stays open.
        if ($outcome instanceof PardonRefusedException) {
            throw $outcome;
        }

        return ['attempt' => $outcome, 'pardons' => $this->pardons->stock($user->getId())];
    }
}
