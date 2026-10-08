<?php

declare(strict_types=1);

namespace App\State\Woodpecker;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Woodpecker\SetPuzzle;
use App\Puzzle\Catalog\PuzzleCatalog;
use App\Repository\Woodpecker\SetPuzzleRepository;
use App\Repository\Woodpecker\SetRepository;
use App\Security\AuthenticatedUser;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * GET /woodpecker/sets/{setId}/puzzles: the user's set list in its order, with each puzzle's
 * played and failed counts.
 *
 * @implements ProviderInterface<SetPuzzle>
 */
final class SetPuzzleProvider implements ProviderInterface
{
    public function __construct(
        private readonly SetRepository $sets,
        private readonly SetPuzzleRepository $setPuzzles,
        private readonly PuzzleCatalog $catalog,
        private readonly AuthenticatedUser $authenticatedUser,
    ) {
    }

    /**
     * @return list<SetPuzzle>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $id = $uriVariables['setId'] ?? null;
        $set = \is_string($id) && Uuid::isValid($id) ? $this->sets->findOwned(Uuid::fromString($id), $this->authenticatedUser->get()) : null;
        if (null === $set) {
            throw new NotFoundHttpException('Set not found.');
        }

        $rows = $this->setPuzzles->findListWithStats($set);
        $puzzles = $this->catalog->byIds(array_column($rows, 'puzzleId'));

        $views = [];
        foreach ($rows as $row) {
            $puzzle = $puzzles[$row['puzzleId']] ?? null;
            if (null !== $puzzle) {
                $views[] = SetPuzzle::from($puzzle, $row['position'], $row['played'], $row['failed']);
            }
        }

        return $views;
    }
}
