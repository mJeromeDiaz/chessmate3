<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Command\ReencryptOAuthTokensCommand;
use App\Entity\AuthIdentity;
use App\Entity\User;
use App\Enum\AuthProvider;
use App\Security\Crypto\SecretBox;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ReencryptOAuthTokensCommandTest extends KernelTestCase
{
    public function testTokensUnderThePreviousKeyAreReencryptedWithTheCurrentOne(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $oldKey = base64_encode(random_bytes(32));
        $newKey = base64_encode(random_bytes(32));

        $user = new User();
        $identity = new AuthIdentity($user, AuthProvider::Lichess, 'magnus');
        $identity->setAccessTokenEncrypted((new SecretBox($oldKey))->encrypt('lio_token'));
        $entityManager->persist($user);
        $entityManager->flush();

        $rotated = new SecretBox($newKey, $oldKey);
        $tester = new CommandTester(new ReencryptOAuthTokensCommand($entityManager, $rotated));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('1 token(s) re-encrypted', $tester->getDisplay());

        $entityManager->clear();
        $reloaded = $entityManager->find(AuthIdentity::class, $identity->getId());
        self::assertInstanceOf(AuthIdentity::class, $reloaded);
        // Readable with the new key alone: the old one can now be dropped.
        self::assertSame('lio_token', (new SecretBox($newKey))->decrypt((string) $reloaded->getAccessTokenEncrypted()));
    }

    public function testUndecryptableTokensAreReportedAndLeftAlone(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $user = new User();
        $identity = new AuthIdentity($user, AuthProvider::Lichess, 'magnus');
        $foreign = (new SecretBox(base64_encode(random_bytes(32))))->encrypt('lio_token');
        $identity->setAccessTokenEncrypted($foreign);
        $entityManager->persist($user);
        $entityManager->flush();

        $tester = new CommandTester(new ReencryptOAuthTokensCommand($entityManager, new SecretBox(base64_encode(random_bytes(32)))));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        $entityManager->clear();
        self::assertSame($foreign, $entityManager->find(AuthIdentity::class, $identity->getId())?->getAccessTokenEncrypted());
    }
}
