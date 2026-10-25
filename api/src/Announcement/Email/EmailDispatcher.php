<?php

declare(strict_types=1);

namespace App\Announcement\Email;

use App\Enum\Announcement\Status;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Queues the announcement emails asked by an admin (docs/ANNOUNCEMENTS.md, § 4), a few recipients
 * per run so that a host's hourly quota (PHP's mail() at OVH) is never hit at once: the email
 * waits for the announcement's start, each recipient is marked queued in its receipt then sent by
 * the async queue ({@see AnnouncementEmailHandler}). Done once nobody is left, or the announcement
 * ended or was archived first. Run every minute with the reminders
 * (`app:training:send-reminders`, or the hosting tick), and when the email is asked.
 */
final readonly class EmailDispatcher
{
    public function __construct(
        private Connection $connection,
        private Recipients $recipients,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        #[Autowire('%env(int:ANNOUNCEMENT_EMAILS_PER_MINUTE)%')]
        private int $perRun,
    ) {
    }

    /**
     * @return int the emails queued
     */
    public function dispatchDue(): int
    {
        $now = $this->clock->now();
        $stored = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $budget = max(1, $this->perRun);
        $queued = 0;
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, status, starts_at, ends_at FROM announcement
              WHERE email_requested_at IS NOT NULL AND email_finished_at IS NULL ORDER BY email_requested_at, id',
        );
        foreach ($rows as $row) {
            $id = Uuid::fromBinary(self::string($row['id']));
            $startsAt = \is_string($row['starts_at']) ? $row['starts_at'] : null;
            $endsAt = \is_string($row['ends_at']) ? $row['ends_at'] : null;
            if (Status::Published->value !== $row['status'] || (null !== $endsAt && $endsAt <= $stored)) {
                $this->finish($id, $stored);
                continue;
            }
            if ((null !== $startsAt && $startsAt > $stored) || $budget <= 0) {
                continue;
            }
            $users = $this->recipients->next($id, $budget);
            if ([] !== $users) {
                $this->markQueued($id, $users, $stored);
                foreach ($users as $user) {
                    $this->bus->dispatch(new AnnouncementEmailDue($id->toRfc4122(), Uuid::fromBinary($user)->toRfc4122()));
                }
            }
            $queued += \count($users);
            if (\count($users) < $budget) {
                $this->finish($id, $stored);
            }
            $budget -= \count($users);
        }

        return $queued;
    }

    /**
     * @param list<string> $users binary ids
     */
    private function markQueued(Uuid $announcementId, array $users, string $now): void
    {
        $values = [];
        foreach ($users as $user) {
            array_push($values, $announcementId->toBinary(), $user, $now);
        }
        $this->connection->executeStatement(
            'INSERT INTO announcement_receipt (announcement_id, user_id, email_queued_at) VALUES '
                .implode(', ', array_fill(0, \count($users), '(?, ?, ?)'))
                .' AS new ON DUPLICATE KEY UPDATE email_queued_at = COALESCE(announcement_receipt.email_queued_at, new.email_queued_at)',
            $values,
            array_merge(...array_fill(0, \count($users), [ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING])),
        );
    }

    private function finish(Uuid $announcementId, string $now): void
    {
        $this->connection->executeStatement(
            'UPDATE announcement SET email_finished_at = ? WHERE id = ? AND email_finished_at IS NULL',
            [$now, $announcementId->toBinary()],
            [ParameterType::STRING, ParameterType::BINARY],
        );
    }

    private static function string(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
