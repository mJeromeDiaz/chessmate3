<?php

declare(strict_types=1);

namespace App\Command\Puzzle;

use App\Puzzle\Theme\ThemeSynchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Loads the Lichess theme reference list into `puzzle_theme` (the fixtures do the same in dev/test).
 * Must run before the puzzle import's selection rebuild, which maps theme keys to these rows.
 */
#[AsCommand(name: 'app:puzzle:sync-themes', description: 'Creates or updates the puzzle themes from the Lichess catalog')]
final class SyncThemesCommand extends Command
{
    public function __construct(private readonly ThemeSynchronizer $synchronizer)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $created = $this->synchronizer->sync();
        (new SymfonyStyle($input, $output))->success(sprintf('Themes synchronized (%d created).', $created));

        return Command::SUCCESS;
    }
}
