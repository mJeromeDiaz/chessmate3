<?php

declare(strict_types=1);

namespace App\Entity\Repertoire;

use App\Entity\Training\Run;
use App\Repository\Repertoire\RunStateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Where a timed repertoire test stands (App\Repertoire\Training\RepertoireModule): its scope, the
 * queue of its round, the unit being played and its counters, as JSON. Only read and written
 * while its run is locked (App\Training\Run\TimeboxRunner).
 */
#[ORM\Entity(repositoryClass: RunStateRepository::class)]
#[ORM\Table(name: 'repertoire_run_state')]
class RunState
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: Run::class)]
    #[ORM\JoinColumn(name: 'run_id', nullable: false, onDelete: 'CASCADE')]
    private Run $run;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $state;

    /**
     * @param array<string, mixed> $state
     */
    public function __construct(Run $run, array $state)
    {
        $this->run = $run;
        $this->state = $state;
    }

    public function getRun(): Run
    {
        return $this->run;
    }

    /**
     * @return array<string, mixed>
     */
    public function getState(): array
    {
        return $this->state;
    }

    /**
     * @param array<string, mixed> $state
     */
    public function setState(array $state): void
    {
        $this->state = $state;
    }
}
