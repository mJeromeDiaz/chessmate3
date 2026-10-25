<?php

declare(strict_types=1);

namespace App\Command\Brilliancy;

use App\Brilliancy\Index\Indexer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Post-import step of « Brillant ou Blunder » (docs/BRILLIANCY.md, § 2), after
 * `app:puzzle:rebuild-selection` (it reads the `selectable` flag): rebuilds `brilliancy_position`
 * from the catalogue, chunk by chunk, while runs keep drawing from it. Rerun it if it stops halfway.
 */
#[AsCommand(name: 'app:brilliancy:rebuild', description: 'Rebuilds the index of the puzzles that show a sacrifice (Brillant ou Blunder)')]
final class RebuildCommand extends Command
{
    public function __construct(private readonly Indexer $indexer)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $progress = $io->createProgressBar();
        $started = microtime(true);

        $counts = $this->indexer->rebuildAll(static function (int $done, int $total) use ($progress): void {
            $progress->setMaxSteps($total);
            $progress->setProgress($done);
        });

        $progress->finish();
        $io->newLine(2);
        $io->success(\sprintf(
            '%d brilliant moves and %d blunders indexed in %.0f s. Run ANALYZE TABLE brilliancy_position; afterwards.',
            $counts['brilliant'],
            $counts['blunder'],
            microtime(true) - $started,
        ));

        return Command::SUCCESS;
    }
}
