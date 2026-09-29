<?php

declare(strict_types=1);

namespace App\State\Woodpecker;

use App\ApiResource\Woodpecker\Set;
use App\Entity\Woodpecker\Set as SetEntity;
use App\Repository\Woodpecker\AttemptRepository;
use App\Repository\Woodpecker\CycleRepository;
use Psr\Clock\ClockInterface;

/**
 * Builds the API view of a set: its runs and their statistics (one grouped query).
 */
final class SetViewFactory
{
    public function __construct(
        private readonly CycleRepository $cycles,
        private readonly AttemptRepository $attempts,
        private readonly ClockInterface $clock,
    ) {
    }

    public function create(SetEntity $set): Set
    {
        $cycles = $this->cycles->findBySet($set);

        return Set::from($set, $cycles, $this->attempts->statsFor($cycles), $this->clock->now());
    }
}
