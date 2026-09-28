<?php

declare(strict_types=1);

namespace App\State\Puzzle;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Puzzle\Rating;
use App\Puzzle\Rating\Exception\LichessNotLinkedException;
use App\Puzzle\Rating\Exception\LichessRatingUnavailableException;
use App\Puzzle\Rating\Exception\RatingAlreadyEstablishedException;
use App\Puzzle\Rating\LichessRatingImporter;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * POST /puzzles/rating/lichess-import. 409 once a rating exists here, 422 when Lichess has none.
 *
 * @implements ProcessorInterface<mixed, Rating>
 */
final class LichessImportProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LichessRatingImporter $importer,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $puzzleLichessImportLimiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Rating
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->puzzleLichessImportLimiter, $user->getId()->toRfc4122());

        try {
            return Rating::from($this->importer->import($user), false);
        } catch (LichessNotLinkedException) {
            throw new UnprocessableEntityHttpException('No linked Lichess account.');
        } catch (LichessRatingUnavailableException) {
            throw new UnprocessableEntityHttpException('No Lichess puzzle rating available.');
        } catch (RatingAlreadyEstablishedException) {
            throw new ConflictHttpException('The puzzle rating is already established.');
        }
    }
}
