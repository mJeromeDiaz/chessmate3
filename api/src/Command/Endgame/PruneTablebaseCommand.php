<?php

declare(strict_types=1);

namespace App\Command\Endgame;

use App\Endgame\Tablebase\Tablebase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes the tablebase answers nobody read for --days (docs/ENDGAMES.md, § 2): the endgame
 * database stays well under its 1 GB. Daily cron (bin/cron-daily.php on the OVH host).
 */
#[AsCommand(name: 'app:endgame:prune-tablebase', description: 'Deletes the tablebase answers unread for a while (endgame_tablebase)')]
final class PruneTablebaseCommand extends Command
{
    public const DEFAULT_DAYS = 90;

    public function __construct(
        private readonly Tablebase $tablebase,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Days without a read', (string) self::DEFAULT_DAYS);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = $input->getOption('days');
        $days = is_numeric($days) ? max(1, (int) $days) : self::DEFAULT_DAYS;
        $deleted = $this->tablebase->prune($this->clock->now()->setTime(0, 0)->modify(\sprintf('-%d days', $days)));
        (new SymfonyStyle($input, $output))->success(\sprintf('%d tablebase answer(s) unread for %d days deleted.', $deleted, $days));

        return Command::SUCCESS;
    }
}
