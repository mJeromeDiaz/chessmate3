<?php

declare(strict_types=1);

namespace App\Command\Repertoire;

use App\Repertoire\Opening\OpeningSynchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Loads the opening names (lichess-org/chess-openings, data/chess-openings) into
 * `repertoire_opening`. Idempotent; the fixtures run it too.
 */
#[AsCommand(name: 'app:repertoire:sync-openings', description: 'Creates or updates the opening names from lichess-org/chess-openings')]
final class SyncOpeningsCommand extends Command
{
    public function __construct(private readonly OpeningSynchronizer $synchronizer)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->synchronizer->sync();
        (new SymfonyStyle($input, $output))->success(sprintf(
            'Openings synchronized: %d positions named (%d duplicate lines skipped, %d removed).',
            $result['loaded'],
            $result['duplicates'],
            $result['removed'],
        ));

        return Command::SUCCESS;
    }
}
