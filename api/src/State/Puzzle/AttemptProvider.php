<?php

declare(strict_types=1);

namespace App\State\Puzzle;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Puzzle\Attempt;
use App\Repository\Puzzle\AttemptRepository;
use App\Security\AuthenticatedUser;
use App\Puzzle\Catalog\PuzzleCatalog;
use Symfony\Component\Uid\Uuid;

/**
 * GET /puzzles/attempts/{id}: the current user's attempt only (null → 404 for anyone else's).
 *
 * @implements ProviderInterface<Attempt>
 */
final class AttemptProvider implements ProviderInterface
{
    public function __construct(
        private readonly PuzzleCatalog $catalog,
        private readonly AttemptRepository $attempts,
        private readonly AuthenticatedUser $authenticatedUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?Attempt
    {
        $id = $uriVariables['id'] ?? null;
        if (!\is_string($id) || !Uuid::isValid($id)) {
            return null;
        }

        $attempt = $this->attempts->findOneBy(['id' => Uuid::fromString($id), 'user' => $this->authenticatedUser->get()]);

        return null === $attempt ? null : Attempt::from($attempt, $this->catalog->get($attempt->getPuzzleId()));
    }
}
