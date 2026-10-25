<?php

declare(strict_types=1);

namespace App\Announcement\Email;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * Who an announcement's email goes to (docs/ANNOUNCEMENTS.md, § 4): the active accounts (neither
 * suspended nor being deleted) with a verified address that did not turn the announcement emails
 * off, not yet queued for that announcement.
 */
final readonly class Recipients
{
    private const WAITING = 'u.suspended_at IS NULL AND u.deletion_scheduled_at IS NULL
        AND u.email IS NOT NULL AND u.email_verified_at IS NOT NULL AND u.announcement_emails = 1
        AND (r.user_id IS NULL OR r.email_queued_at IS NULL)';

    public function __construct(private Connection $connection)
    {
    }

    /** The accounts an email asked now would serve (no announcement: none queued yet). */
    public function count(?Uuid $announcementId = null): int
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM app_user u
               LEFT JOIN announcement_receipt r ON r.announcement_id = ? AND r.user_id = u.id
              WHERE '.self::WAITING,
            [($announcementId ?? Uuid::fromString('00000000-0000-0000-0000-000000000000'))->toBinary()],
            [ParameterType::BINARY],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * The next accounts to queue, in id order.
     *
     * @return list<string> binary ids
     */
    public function next(Uuid $announcementId, int $limit): array
    {
        /** @var list<string> */
        return $this->connection->fetchFirstColumn(
            'SELECT u.id FROM app_user u
               LEFT JOIN announcement_receipt r ON r.announcement_id = ? AND r.user_id = u.id
              WHERE '.self::WAITING.' ORDER BY u.id LIMIT '.max(1, $limit),
            [$announcementId->toBinary()],
            [ParameterType::BINARY],
        );
    }
}
