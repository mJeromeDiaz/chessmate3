<?php

declare(strict_types=1);

namespace App\EarlyAccess\Stats;

use App\Dashboard\Period;
use Doctrine\DBAL\Connection;

/**
 * What became of the invitations created during the period (docs/EARLY_ACCESS.md): their status
 * today, their email delivery, the share used, and how long a used key waited for its sign-up.
 *
 * @phpstan-type Funnel array{created: int, pending: int, used: int, expired: int, revoked: int, emailSent: int, emailFailed: int, conversionRate: float|null, medianSecondsToUse: int|null, pendingNow: int}
 */
final class InvitationFunnel
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return Funnel
     */
    public function compute(Period $period, \DateTimeImmutable $now): array
    {
        $params = ['since' => Sql::instant($period->since), 'now' => Sql::instant($now)];
        $row = $this->connection->fetchAssociative(
            "SELECT COUNT(*) AS created,
                    SUM(used_at IS NOT NULL) AS used,
                    SUM(used_at IS NULL AND revoked_at IS NOT NULL) AS revoked,
                    SUM(used_at IS NULL AND revoked_at IS NULL AND expires_at <= :now) AS expired,
                    SUM(email_status = 'sent') AS email_sent,
                    SUM(email_status = 'failed') AS email_failed
               FROM early_access_invitation_key
              WHERE created_at >= :since",
            $params,
        ) ?: [];
        $created = Sql::int($row['created'] ?? 0);
        $used = Sql::int($row['used'] ?? 0);
        $revoked = Sql::int($row['revoked'] ?? 0);
        $expired = Sql::int($row['expired'] ?? 0);

        $waits = array_map(Sql::int(...), $this->connection->fetchFirstColumn(
            'SELECT TIMESTAMPDIFF(SECOND, created_at, used_at) FROM early_access_invitation_key
              WHERE created_at >= :since AND used_at IS NOT NULL',
            $params,
        ));

        $pendingNow = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM early_access_invitation_key
              WHERE used_at IS NULL AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > :now)',
            ['now' => $params['now']],
        );

        return [
            'created' => $created,
            'pending' => $created - $used - $revoked - $expired,
            'used' => $used,
            'expired' => $expired,
            'revoked' => $revoked,
            'emailSent' => Sql::int($row['email_sent'] ?? 0),
            'emailFailed' => Sql::int($row['email_failed'] ?? 0),
            'conversionRate' => 0 === $created ? null : round($used / $created, 4),
            'medianSecondsToUse' => Sql::median($waits),
            'pendingNow' => Sql::int($pendingNow),
        ];
    }
}
