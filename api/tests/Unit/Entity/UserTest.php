<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\AuthIdentity;
use App\Entity\User;
use App\Enum\AuthProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class UserTest extends TestCase
{
    public function testUserIdentifierIsTheUuidNotTheEmail(): void
    {
        $user = new User();
        $user->setEmail('alice@example.com');

        self::assertSame($user->getId()->toRfc4122(), $user->getUserIdentifier());
        self::assertTrue(Uuid::isValid($user->getUserIdentifier()));
    }

    public function testRolesAlwaysIncludeRoleUser(): void
    {
        $user = new User();

        self::assertSame(['ROLE_USER'], $user->getRoles());

        $user->setRoles(['ROLE_ADMIN']);

        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $user->getRoles());
    }

    public function testCountAuthMethodsWithNoMethodAtAll(): void
    {
        $user = new User();

        self::assertSame(0, $user->countAuthMethods());
    }

    public function testCountAuthMethodsWithPasswordOnly(): void
    {
        $user = new User();
        $user->setEmail('a@example.com')->markEmailVerified();
        $user->setPassword('hashed');

        self::assertSame(1, $user->countAuthMethods());
    }

    /**
     * A password without a verified email can't be used to sign in, so it isn't a method.
     */
    public function testPasswordWithoutVerifiedEmailIsNotAnAuthMethod(): void
    {
        $noEmail = (new User())->setPassword('hashed');
        $unverified = (new User())->setPassword('hashed')->setEmail('a@example.com');
        $pending = (new User())->setPassword('hashed')->setPendingEmail('b@example.com');

        self::assertSame([0, 0, 0], [$noEmail->countAuthMethods(), $unverified->countAuthMethods(), $pending->countAuthMethods()]);
    }

    public function testConfirmingThePendingEmailMakesItTheVerifiedEmail(): void
    {
        $user = new User();
        $user->setPendingEmail('b@example.com');

        $user->confirmPendingEmail();

        self::assertSame('b@example.com', $user->getEmail());
        self::assertNull($user->getPendingEmail());
        self::assertTrue($user->isEmailVerified());
    }

    public function testCountAuthMethodsWithPasswordAndOneLinkedProvider(): void
    {
        $user = new User();
        $user->setEmail('a@example.com')->markEmailVerified();
        $user->setPassword('hashed');
        new AuthIdentity($user, AuthProvider::Google, 'google-123');

        self::assertSame(2, $user->countAuthMethods());
    }

    public function testEmailIsNotVerifiedByDefault(): void
    {
        $user = new User();

        self::assertFalse($user->isEmailVerified());

        $user->markEmailVerified();

        self::assertTrue($user->isEmailVerified());
    }
}
