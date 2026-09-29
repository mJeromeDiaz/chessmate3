<?php

declare(strict_types=1);

namespace App\Command\Activity;

use App\Activity\Backfill\SourceInterface;
use App\Activity\Log\ActivityLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Writes the activity log entries of exercises completed before the log existed. Idempotent and
 * resumable: an exercise already logged is skipped (unique source key), so it can be re-run at will.
 * Local dates use each user's timezone as known *now* (docs/ACTIVITY.md).
 */
#[AsCommand(name: 'app:activity:backfill', description: 'Logs past exercises in the activity log (idempotent)')]
final class BackfillCommand extends Command
{
    /**
     * @param iterable<SourceInterface> $sources
     */
    public function __construct(
        private readonly ActivityLogger $logger,
        #[AutowireIterator(SourceInterface::TAG)]
        private readonly iterable $sources,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Exercises per batch', '1000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $batchSize = max(1, (int) (\is_numeric($input->getOption('batch-size')) ? $input->getOption('batch-size') : 1000));

        foreach ($this->sources as $source) {
            $logged = 0;
            $skipped = 0;
            foreach ($source->batches($batchSize) as $events) {
                foreach ($events as $event) {
                    $this->logger->record($event) ? ++$logged : ++$skipped;
                }
            }
            $io->text(sprintf('%s: %d logged, %d already present.', $source->getName(), $logged, $skipped));
        }

        $io->success('Activity log backfilled.');

        return Command::SUCCESS;
    }
}
