<?php

declare(strict_types=1);

namespace App\State\Woodpecker;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Woodpecker\StubbornPuzzle;
use App\Puzzle\Catalog\PuzzleCatalog;
use App\Repository\Woodpecker\AttemptRepository;
use App\Repository\Woodpecker\SetRepository;
use App\Security\AuthenticatedUser;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * GET /woodpecker/sets/{setId}/stubborn: puzzles failed in at least two cycles of the user's set.
 *
 * @implements ProviderInterface<StubbornPuzzle>
 */
final class StubbornProvider implements ProviderInterface
{
    public function __construct(
        private readonly SetRepository $sets,
        private readonly AttemptRepository $attempts,
        private readonly PuzzleCatalog $catalog,
        private readonly AuthenticatedUser $authenticatedUser,
    ) {
    }

    /**
     * @return list<StubbornPuzzle>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $id = $uriVariables['setId'] ?? null;
        $set = \is_string($id) && Uuid::isValid($id) ? $this->sets->findOwned(Uuid::fromString($id), $this->authenticatedUser->get()) : null;
        if (null === $set) {
            throw new NotFoundHttpException('Set not found.');
        }

        $rows = $this->attempts->findStubborn($set);
        $puzzles = $this->catalog->byIds(array_column($rows, 'puzzleId'));

        $views = [];
        foreach ($rows as $row) {
            $puzzle = $puzzles[$row['puzzleId']] ?? null;
            if (null === $puzzle) {
                continue;
            }
            $view = new StubbornPuzzle();
            $view->puzzleId = $puzzle->getLichessId();
            $view->rating = $puzzle->getRating();
            $view->themes = $puzzle->getThemes();
            $view->failedCycles = $row['failedCycles'];
            $views[] = $view;
        }

        return $views;
    }
}
