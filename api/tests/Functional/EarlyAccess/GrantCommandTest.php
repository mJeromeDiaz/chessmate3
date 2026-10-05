<?php

declare(strict_types=1);

namespace App\Tests\Functional\EarlyAccess;

use App\Entity\AuditLogEntry;
use App\Enum\AuditEventType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:admin:grant (docs/EARLY_ACCESS.md): the only way to give or take back the admin role,
 * effective on the next request.
 */
final class GrantCommandTest extends EarlyAccessWebTestCase
{
    public function testGrantingAndRevokingTheAdminRole(): void
    {
        $player = $this->createUser('player@example.com');
        self::assertSame(403, $this->api('GET', '/api/admin/invitation-keys', $player)->getStatusCode());

        self::assertSame(Command::SUCCESS, $this->grant(['email' => ' Player@Example.com ']));
        self::assertSame(200, $this->api('GET', '/api/admin/invitation-keys', $player)->getStatusCode(), 'Effective at once.');
        self::assertSame(Command::SUCCESS, $this->grant(['email' => 'player@example.com']), 'Idempotent.');
        // The client rebooted the kernel: read the account from the current container.
        $reloaded = self::getContainer()->get(UserRepository::class)->findOneByEmail('player@example.com');
        self::assertNotNull($reloaded);
        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $reloaded->getRoles());

        self::assertSame(Command::SUCCESS, $this->grant(['email' => 'player@example.com', '--revoke' => true]));
        self::assertSame(403, $this->api('GET', '/api/admin/invitation-keys', $player)->getStatusCode());

        self::assertSame(
            [AuditEventType::AdminGranted, AuditEventType::AdminRevoked],
            array_map(static fn (AuditLogEntry $entry): AuditEventType => $entry->getEventType(), self::getContainer()->get(EntityManagerInterface::class)->getRepository(AuditLogEntry::class)->findBy(['user' => $player->getId()], ['createdAt' => 'ASC', 'id' => 'ASC'])),
        );
    }

    public function testAnUnknownAccountFails(): void
    {
        self::assertSame(Command::FAILURE, $this->grant(['email' => 'nobody@example.com']));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function grant(array $input): int
    {
        $application = new Application(self::$kernel ?? throw new \LogicException('No kernel.'));
        $tester = new CommandTester($application->find('app:admin:grant'));

        return $tester->execute($input);
    }
}
