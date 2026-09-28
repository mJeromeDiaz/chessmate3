<?php

declare(strict_types=1);

namespace App\Tests\Functional\Entity;

use App\Entity\AuthIdentity;
use App\Entity\User;
use App\Enum\AuthProvider;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UserPersistenceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testUserWithLinkedIdentityIsPersistedAndReloadedCorrectly(): void
    {
        $user = new User();
        $user->setEmail('bob@example.com');
        new AuthIdentity($user, AuthProvider::Lichess, 'lichess-42');

        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->entityManager->clear();

        /** @var UserRepository $repository */
        $repository = self::getContainer()->get(UserRepository::class);
        $reloaded = $repository->findOneByEmail('bob@example.com');

        self::assertNotNull($reloaded);
        self::assertSame(1, $reloaded->countAuthMethods());

        $identity = $reloaded->getAuthIdentities()->first();
        self::assertInstanceOf(AuthIdentity::class, $identity);
        self::assertSame(AuthProvider::Lichess, $identity->getProvider());
    }
}
