<?php

declare(strict_types=1);

namespace App\State\Puzzle;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Puzzle\Rating;
use App\Puzzle\Rating\LichessRatingImporter;
use App\Repository\Puzzle\RatingRepository;
use App\Security\AuthenticatedUser;

/**
 * GET /puzzles/rating. A user who never played has the default rating (no row is created here).
 *
 * @implements ProviderInterface<Rating>
 */
final class RatingProvider implements ProviderInterface
{
    public function __construct(
        private readonly RatingRepository $ratings,
        private readonly LichessRatingImporter $importer,
        private readonly AuthenticatedUser $authenticatedUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Rating
    {
        $user = $this->authenticatedUser->get();
        $rating = $this->ratings->findOneByUser($user);

        return Rating::from($rating, $this->importer->canImport($user, $rating));
    }
}
