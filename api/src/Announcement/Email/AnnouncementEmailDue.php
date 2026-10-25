<?php

declare(strict_types=1);

namespace App\Announcement\Email;

/**
 * An announcement's email to send to one player (docs/ANNOUNCEMENTS.md, § 4), queued by
 * {@see EmailDispatcher}. Scalars only.
 */
final readonly class AnnouncementEmailDue
{
    public function __construct(
        public string $announcementId,
        public string $userId,
    ) {
    }
}
