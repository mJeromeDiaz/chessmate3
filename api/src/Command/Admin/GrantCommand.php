<?php

declare(strict_types=1);

namespace App\Command\Admin;

use App\Enum\AuditEventType;
use App\Repository\UserRepository;
use App\Security\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Gives ROLE_ADMIN to an account, or takes it back (docs/EARLY_ACCESS.md). The only way to make an
 * admin: no endpoint writes roles. Effective on the next request (the user is reloaded from the
 * database on each one). Idempotent.
 */
#[AsCommand(name: 'app:admin:grant', description: 'Gives the admin role to an account (or takes it back with --revoke)')]
final class GrantCommand
{
    public const ROLE = 'ROLE_ADMIN';

    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Email address of the account')] string $email,
        #[Option(description: 'Take the admin role back')] bool $revoke = false,
    ): int {
        $user = $this->users->findOneByEmail(mb_strtolower(trim($email)));
        if (null === $user) {
            $io->error('No account with this email address.');

            return Command::FAILURE;
        }

        $roles = array_values(array_filter($user->getRoles(), static fn (string $role): bool => 'ROLE_USER' !== $role && self::ROLE !== $role));
        if (!$revoke) {
            $roles[] = self::ROLE;
        }
        $changed = $revoke === \in_array(self::ROLE, $user->getRoles(), true);
        $user->setRoles($roles);
        $this->entityManager->flush();
        if ($changed) {
            $this->auditLogger->log($revoke ? AuditEventType::AdminRevoked : AuditEventType::AdminGranted, $user);
        }

        $io->success(\sprintf('%s %s admin.', $user->getEmail(), $revoke ? 'is no longer' : 'is'));

        return Command::SUCCESS;
    }
}
