<?php

declare(strict_types=1);

namespace App\Announcement\Admin;

use App\Announcement\Email\Recipients;
use App\Entity\Announcement\Announcement;
use App\Enum\Announcement\Status;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The announcements as the admin page shows them (docs/ANNOUNCEMENTS.md, § 2): the texts, where
 * each stands (`draft`, `scheduled`, `live`, `ended`, `archived`), how many players closed it, and
 * its email (asked, queued, sent, done).
 *
 * @phpstan-type EmailView array{requestedAt: string|null, finishedAt: string|null, queued: int, sent: int}
 * @phpstan-type AdminView array{id: string, titleFr: string, bodyFr: string, titleEn: string|null, bodyEn: string|null, status: string, state: string, startsAt: string|null, endsAt: string|null, publishedAt: string|null, createdAt: string, updatedAt: string, author: string|null, reads: int, email: EmailView}
 */
final readonly class AnnouncementViews
{
    public const LIMIT = 50;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
        private Recipients $recipients,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The latest first, archived ones included.
     *
     * @return list<AdminView>
     */
    public function list(): array
    {
        /** @var list<Announcement> $announcements */
        $announcements = $this->entityManager->getRepository(Announcement::class)->findBy([], ['createdAt' => 'DESC'], self::LIMIT);
        $counts = $this->counts($announcements);

        return array_map(fn (Announcement $announcement): array => $this->view($announcement, $counts), $announcements);
    }

    /**
     * @return AdminView
     */
    public function one(Announcement $announcement): array
    {
        return $this->view($announcement, $this->counts([$announcement]));
    }

    /** The accounts an email asked now would serve. */
    public function recipients(): int
    {
        return $this->recipients->count();
    }

    /**
     * @param array<string, array{reads: int, queued: int, sent: int}> $counts by binary id
     *
     * @return AdminView
     */
    private function view(Announcement $announcement, array $counts): array
    {
        $count = $counts[$announcement->getId()->toBinary()] ?? ['reads' => 0, 'queued' => 0, 'sent' => 0];

        return [
            'id' => $announcement->getId()->toRfc4122(),
            'titleFr' => $announcement->getTitleFr(),
            'bodyFr' => $announcement->getBodyFr(),
            'titleEn' => $announcement->getTitleEn(),
            'bodyEn' => $announcement->getBodyEn(),
            'status' => $announcement->getStatus()->value,
            'state' => $this->state($announcement),
            'startsAt' => self::instant($announcement->getStartsAt()),
            'endsAt' => self::instant($announcement->getEndsAt()),
            'publishedAt' => self::instant($announcement->getPublishedAt()),
            'createdAt' => (string) self::instant($announcement->getCreatedAt()),
            'updatedAt' => (string) self::instant($announcement->getUpdatedAt()),
            'author' => $announcement->getAuthor()?->getEmail(),
            'reads' => $count['reads'],
            'email' => [
                'requestedAt' => self::instant($announcement->getEmailRequestedAt()),
                'finishedAt' => self::instant($announcement->getEmailFinishedAt()),
                'queued' => $count['queued'],
                'sent' => $count['sent'],
            ],
        ];
    }

    private function state(Announcement $announcement): string
    {
        $now = $this->clock->now();

        return match (true) {
            Status::Draft === $announcement->getStatus() => 'draft',
            Status::Archived === $announcement->getStatus() => 'archived',
            $announcement->hasEnded($now) => 'ended',
            $announcement->isLive($now) => 'live',
            default => 'scheduled',
        };
    }

    /**
     * @param list<Announcement> $announcements
     *
     * @return array<string, array{reads: int, queued: int, sent: int}> by binary id
     */
    private function counts(array $announcements): array
    {
        if ([] === $announcements) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT announcement_id, COUNT(read_at) AS reads_count, COUNT(email_queued_at) AS queued, COUNT(emailed_at) AS sent
               FROM announcement_receipt WHERE announcement_id IN (?) GROUP BY announcement_id',
            [array_map(static fn (Announcement $announcement): string => $announcement->getId()->toBinary(), $announcements)],
            [ArrayParameterType::BINARY],
        );
        $counts = [];
        foreach ($rows as $row) {
            $counts[self::string($row['announcement_id'])] = [
                'reads' => (int) self::string($row['reads_count']),
                'queued' => (int) self::string($row['queued']),
                'sent' => (int) self::string($row['sent']),
            ];
        }

        return $counts;
    }

    private static function instant(?\DateTimeImmutable $instant): ?string
    {
        return $instant?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private static function string(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
