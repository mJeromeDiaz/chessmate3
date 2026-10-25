<?php

declare(strict_types=1);

namespace App\Announcement\Display;

use App\Entity\User;
use App\Enum\Announcement\Status;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The announcements a player has still to see (docs/ANNOUNCEMENTS.md, § 3): published, started,
 * not ended, not yet closed by the player, the oldest first, in the player's language. An account
 * created after the publication sees them too.
 *
 * @phpstan-type CurrentView array{id: string, title: string, body: string, startsAt: string}
 */
final readonly class CurrentAnnouncements
{
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<CurrentView>
     */
    public function of(User $user, string $locale): array
    {
        $now = self::stored($this->clock->now());
        $rows = $this->connection->fetchAllAssociative(
            'SELECT a.id, a.title_fr, a.body_fr, a.title_en, a.body_en, COALESCE(a.starts_at, a.published_at) AS shown_from
               FROM announcement a
               LEFT JOIN announcement_receipt r ON r.announcement_id = a.id AND r.user_id = ?
              WHERE a.status = ? AND (a.starts_at IS NULL OR a.starts_at <= ?) AND (a.ends_at IS NULL OR a.ends_at > ?)
                AND r.read_at IS NULL
              ORDER BY shown_from, a.id',
            [$user->getId()->toBinary(), Status::Published->value, $now, $now],
            [ParameterType::BINARY, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
        );

        return array_map(static function (array $row) use ($locale): array {
            $english = 'en' === $locale && \is_string($row['title_en']) && \is_string($row['body_en']);

            return [
                'id' => Uuid::fromBinary(self::string($row['id']))->toRfc4122(),
                'title' => self::string($english ? $row['title_en'] : $row['title_fr']),
                'body' => self::string($english ? $row['body_en'] : $row['body_fr']),
                'startsAt' => str_replace(' ', 'T', self::string($row['shown_from'])).'Z',
            ];
        }, $rows);
    }

    /** The player closed its modal (again: nothing changes). */
    public function markRead(Uuid $announcementId, User $user): void
    {
        $now = self::stored($this->clock->now());
        $this->connection->executeStatement(
            'INSERT INTO announcement_receipt (announcement_id, user_id, read_at) SELECT id, ?, ? FROM announcement WHERE id = ?
                 ON DUPLICATE KEY UPDATE read_at = COALESCE(announcement_receipt.read_at, ?)',
            [$user->getId()->toBinary(), $now, $announcementId->toBinary(), $now],
            [ParameterType::BINARY, ParameterType::STRING, ParameterType::BINARY, ParameterType::STRING],
        );
    }

    private static function stored(\DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function string(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
