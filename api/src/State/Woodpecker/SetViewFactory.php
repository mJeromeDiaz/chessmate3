<?php

declare(strict_types=1);

namespace App\State\Woodpecker;

use App\ApiResource\Woodpecker\Set;
use App\Entity\Woodpecker\Set as SetEntity;
use App\Enum\Woodpecker\SetMode;
use App\Repository\Training\RunRepository;
use App\Repository\Woodpecker\AttemptRepository;
use App\Repository\Woodpecker\CycleRepository;
use App\Repository\Woodpecker\GrowthRepository;
use App\Woodpecker\Training\WoodpeckerModule;
use Psr\Clock\ClockInterface;

/**
 * Builds the API view of a set: its runs and their statistics (one grouped query).
 */
final class SetViewFactory
{
    public function __construct(
        private readonly CycleRepository $cycles,
        private readonly AttemptRepository $attempts,
        private readonly GrowthRepository $growths,
        private readonly RunRepository $runs,
        private readonly ClockInterface $clock,
    ) {
    }

    public function create(SetEntity $set): Set
    {
        $cycles = $this->cycles->findBySet($set);

        $growths = SetMode::Light === $set->getMode() ? $this->growths->findBySet($set) : [];

        $runs = $this->runs->findClosedBySubject(WoodpeckerModule::SUBJECT_TYPE, $set->getId());

        return Set::from($set, $cycles, $this->attempts->statsFor($cycles), $growths, $runs, $this->clock->now());
    }
}
