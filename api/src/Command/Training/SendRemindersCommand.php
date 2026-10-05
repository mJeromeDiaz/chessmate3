<?php

declare(strict_types=1);

namespace App\Command\Training;

use App\Training\Plan\Reminder\ReminderDispatcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sends the reminders now due (docs/TRAINING.md): run by cron every minute
 * (`* * * * * bin/console app:training:send-reminders`). Each occurrence is claimed once in the
 * reminder log, then sent by the async worker. On shared hosting, the tick does it instead
 * (docs/DEPLOY_OVH.md).
 */
#[AsCommand(name: 'app:training:send-reminders', description: 'Sends the reminders of saved sessions now due (cron, every minute)')]
final class SendRemindersCommand
{
    public function __construct(private readonly ReminderDispatcher $reminders)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $io->writeln(sprintf('%d reminder(s) queued.', $this->reminders->dispatchDue()));

        return Command::SUCCESS;
    }
}
