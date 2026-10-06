<?php

declare(strict_types=1);

namespace App\Command\Puzzle;

use App\Puzzle\Selection\Quality;
use App\Puzzle\Selection\SelectionRebuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Post-import step (docs/PUZZLE_IMPORT.md), also needed after changing the {@see Quality}
 * thresholds: recomputes `puzzle.selectable`, rebuilds `puzzle_theme_membership` and the
 * per-theme counts. Not an import: it only reads the `puzzle` table. Themed puzzles are paused
 * meanwhile ({@see SelectionRebuilder::rebuildAll()}).
 */
#[AsCommand(name: 'app:puzzle:rebuild-selection', description: 'Rebuilds the puzzle selection index and theme counts')]
final class RebuildSelectionCommand extends Command
{
    public function __construct(private readonly SelectionRebuilder $rebuilder)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->text(sprintf('Quality thresholds: popularity >= %d, plays >= %d.', Quality::MIN_POPULARITY, Quality::MIN_PLAYS));
        $progress = $io->createProgressBar();

        $this->rebuilder->rebuildAll(static function (int $done, int $total) use ($progress): void {
            $progress->setMaxSteps($total);
            $progress->setProgress($done);
        });

        $progress->finish();
        $io->newLine(2);
        $io->success('Selection index rebuilt. Run ANALYZE TABLE puzzle, puzzle_theme_membership; afterwards.');

        return Command::SUCCESS;
    }
}
