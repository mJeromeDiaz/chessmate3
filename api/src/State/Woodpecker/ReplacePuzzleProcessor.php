<?php

declare(strict_types=1);

namespace App\State\Woodpecker;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Woodpecker\SetPuzzle;
use App\Puzzle\Catalog\PuzzleCatalog;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use App\Woodpecker\Exception\NotEnoughPuzzlesException;
use App\Woodpecker\Exception\PuzzleNotInSetException;
use App\Woodpecker\Exception\SetNotFoundException;
use App\Woodpecker\Exception\SetNotPlayableException;
use App\Woodpecker\Set\PuzzleReplacer;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Uid\Uuid;

/**
 * POST /woodpecker/sets/{setId}/puzzles/{puzzleId}/replace: the new puzzle, at the old one's
 * position. 404 unknown set or puzzle not in it, 409 set completed or abandoned, 422 no other
 * puzzle of the set's profile left. Shares the "next puzzle" rate limit (a replacement serves one).
 *
 * @implements ProcessorInterface<mixed, SetPuzzle>
 */
final class ReplacePuzzleProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly PuzzleReplacer $replacer,
        private readonly PuzzleCatalog $catalog,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $woodpeckerAttemptStartLimiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SetPuzzle
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->woodpeckerAttemptStartLimiter, $user->getId()->toRfc4122());
        $setId = $uriVariables['setId'] ?? null;
        $puzzleId = $uriVariables['puzzleId'] ?? null;
        if (!\is_string($setId) || !Uuid::isValid($setId) || !\is_string($puzzleId)) {
            throw new NotFoundHttpException();
        }

        try {
            $replaced = $this->replacer->replace($user, Uuid::fromString($setId), $puzzleId);
        } catch (SetNotFoundException) {
            throw new NotFoundHttpException('Set not found.');
        } catch (PuzzleNotInSetException $e) {
            throw new NotFoundHttpException($e->getMessage());
        } catch (SetNotPlayableException $e) {
            throw new ConflictHttpException($e->getMessage());
        } catch (NotEnoughPuzzlesException) {
            throw new UnprocessableEntityHttpException('No other puzzle matches this set.');
        }

        return SetPuzzle::from($this->catalog->get($replaced['puzzleId']), $replaced['position'], 0, 0);
    }
}
