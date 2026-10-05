<?php

declare(strict_types=1);

namespace App\Security\Account;

use App\Entity\User;
use App\Enum\AuditEventType;
use App\Repository\UserRepository;
use App\Security\Audit\AuditLogger;
use App\Security\OAuth\OAuthTokenVault;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Purges the accounts whose deletion is due (docs/AUTH.md), one transaction each:
 *
 * - the OAuth tokens kept for the user are revoked at the provider (best effort), as on an unlink;
 * - the refresh tokens (keyed by the user identifier, without a foreign key) are deleted;
 * - the security journal keeps its rows, unlinked, with the IP, user agent and details erased;
 * - the user row is deleted: every table that belongs to the user follows (ON DELETE CASCADE);
 * - an `account_deleted` entry, linked to no one and without any personal data, records it.
 */
final readonly class AccountPurger
{
    public function __construct(
        private UserRepository $users,
        private OAuthTokenVault $tokenVault,
        private AuditLogger $auditLogger,
        private EntityManagerInterface $entityManager,
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return int accounts purged
     */
    public function purgeDue(): int
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $purged = 0;
        foreach ($this->users->findDeletionDue($now) as $user) {
            $this->purge($user);
            ++$purged;
        }

        return $purged;
    }

    private function purge(User $user): void
    {
        foreach ($user->getAuthIdentities() as $identity) {
            $this->tokenVault->revoke($identity);
        }
        $this->entityManager->flush();

        $id = $user->getId()->toBinary();
        $this->connection->transactional(function (Connection $connection) use ($user, $id): void {
            $connection->executeStatement('DELETE FROM refresh_token WHERE username = ?', [$user->getUserIdentifier()]);
            $connection->executeStatement(
                "UPDATE audit_log_entry SET user_id = NULL, ip = NULL, user_agent = NULL, metadata = '{}' WHERE user_id = ?",
                [$id],
                [ParameterType::BINARY],
            );
            $connection->executeStatement('DELETE FROM app_user WHERE id = ?', [$id], [ParameterType::BINARY]);
        });
        // Raw SQL deleted what the identity map may still hold.
        $this->entityManager->clear();

        $this->auditLogger->log(AuditEventType::AccountDeleted);
    }
}
