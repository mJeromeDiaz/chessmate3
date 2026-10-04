<?php

declare(strict_types=1);

namespace App\Command\Training;

use App\Repository\Training\ReminderLogRepository;
use App\Training\Plan\Reminder\ReminderScheduler;
use App\Training\Plan\Reminder\SessionReminderDue;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Sends the reminders now due (docs/TRAINING.md): run by cron every minute
 * (`* * * * * bin/console app:training:send-reminders`). Each occurrence is claimed once in the
 * reminder log, then sent by the async worker.
 */
#[AsCommand(name: 'app:training:send-reminders', description: 'Sends the reminders of saved sessions now due (cron, every minute)')]
final class SendRemindersCommand
{
    public function __construct(
        private readonly ReminderScheduler $scheduler,
        private readonly ReminderLogRepository $log,
        private readonly MessageBusInterface $bus,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $sent = 0;
        foreach ($this->scheduler->due($now) as [$plan, $occursAt]) {
            if (!$this->log->claim($plan, $occursAt, $now)) {
                continue;
            }
            $this->bus->dispatch(new SessionReminderDue($plan->getId()->toRfc4122(), $occursAt->format(\DATE_ATOM)));
            ++$sent;
        }
        $io->writeln(sprintf('%d reminder(s) queued.', $sent));

        return Command::SUCCESS;
    }
}
