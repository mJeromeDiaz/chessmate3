<?php

declare(strict_types=1);

namespace App\State\Puzzle;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Puzzle\Puzzle;
use App\Repository\Catalog\PuzzleRepository;

/**
 * @implements ProviderInterface<Puzzle>
 */
final class PuzzleProvider implements ProviderInterface
{
    public function __construct(private readonly PuzzleRepository $puzzles)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?Puzzle
    {
        $id = $uriVariables['id'] ?? null;
        $puzzle = \is_string($id) ? $this->puzzles->findOneByLichessId($id) : null;

        return null === $puzzle ? null : Puzzle::from($puzzle);
    }
}
