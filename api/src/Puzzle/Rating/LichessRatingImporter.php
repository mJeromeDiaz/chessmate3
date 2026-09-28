<?php

declare(strict_types=1);

namespace App\Puzzle\Rating;

use App\Entity\AuthIdentity;
use App\Entity\Puzzle\Rating;
use App\Entity\Puzzle\RatingChange;
use App\Entity\User;
use App\Enum\AuthProvider;
use App\Enum\Puzzle\RatingChangeReason;
use App\Enum\Puzzle\RatingSource;
use App\Puzzle\Rating\Exception\LichessNotLinkedException;
use App\Puzzle\Rating\Exception\LichessRatingUnavailableException;
use App\Puzzle\Rating\Exception\RatingAlreadyEstablishedException;
use App\Repository\Puzzle\RatingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Seeds the puzzle rating from the user's Lichess puzzle rating (public `GET /api/user/{id}`,
 * `perfs.puzzle`), once, before any rated attempt: afterwards the rating reflects play here and
 * overwriting it would erase that history.
 */
final class LichessRatingImporter
{
    public function __construct(
        private readonly HttpClientInterface $lichessApiClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly RatingRepository $ratings,
    ) {
    }

    public function canImport(User $user, ?Rating $rating): bool
    {
        return null !== $this->lichessIdentity($user)
            && (null === $rating || (0 === $rating->getRatedCount() && RatingSource::Default === $rating->getSource()));
    }

    /**
     * @throws LichessNotLinkedException
     * @throws LichessRatingUnavailableException
     * @throws RatingAlreadyEstablishedException
     */
    public function import(User $user): Rating
    {
        $identity = $this->lichessIdentity($user) ?? throw new LichessNotLinkedException();

        // Called before taking the lock: never hold a row lock across a network call.
        $current = $this->ratings->findOneByUser($user);
        if (!$this->canImport($user, $current)) {
            throw new RatingAlreadyEstablishedException();
        }
        [$lichessRating, $lichessDeviation] = $this->fetchPuzzleRating($identity->getProviderUserId());

        return $this->entityManager->wrapInTransaction(function () use ($user, $lichessRating, $lichessDeviation): Rating {
            $rating = $this->ratings->lockForUser($user);
            if (!$this->canImport($user, $rating)) {
                throw new RatingAlreadyEstablishedException();
            }

            $now = new \DateTimeImmutable();
            $before = $rating->getState();
            $after = RatingCalculator::fromLichess($lichessRating, $lichessDeviation);
            $rating->seedFromLichess($after, $now);
            $this->entityManager->persist(new RatingChange($user, RatingChangeReason::LichessImport, $before, $after, $now));

            return $rating;
        });
    }

    private function lichessIdentity(User $user): ?AuthIdentity
    {
        foreach ($user->getAuthIdentities() as $identity) {
            if (AuthProvider::Lichess === $identity->getProvider()) {
                return $identity;
            }
        }

        return null;
    }

    /**
     * @return array{float, float} rating and RD
     */
    private function fetchPuzzleRating(string $lichessUserId): array
    {
        try {
            $data = $this->lichessApiClient->request('GET', '/api/user/'.rawurlencode($lichessUserId))->toArray();
        } catch (ExceptionInterface) {
            throw new LichessRatingUnavailableException();
        }

        $puzzle = \is_array($data['perfs'] ?? null) && \is_array($data['perfs']['puzzle'] ?? null) ? $data['perfs']['puzzle'] : [];
        $rating = $puzzle['rating'] ?? null;
        $deviation = $puzzle['rd'] ?? null;
        $games = $puzzle['games'] ?? 0;

        if (!\is_int($rating) || !\is_int($deviation) || !\is_int($games) || $games < 1) {
            throw new LichessRatingUnavailableException();
        }

        return [(float) $rating, (float) $deviation];
    }
}
