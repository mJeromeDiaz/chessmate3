<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

/**
 * Access tokens carry the user's token version ("ver" claim); one that doesn't match the user's
 * current version is rejected like any invalid token.
 */
final class AccessTokenVersionTest extends AuthWebTestCase
{
    public function testTokenCarriesTheCurrentVersion(): void
    {
        $user = $this->createVerifiedUser('alice@example.com');
        $payload = $this->jwtManager()->parse($this->jwtManager()->create($user));

        self::assertSame(0, $payload['ver'] ?? null);
    }

    public function testTokenIsRejectedOnceTheVersionIsBumped(): void
    {
        $user = $this->createVerifiedUser('alice@example.com');
        $accessToken = $this->jwtManager()->create($user);
        self::assertSame(200, $this->statusWithAccessToken($accessToken));

        $this->setOnAllRows(User::class, 'tokenVersion', 1);

        self::assertSame(401, $this->statusWithAccessToken($accessToken));
    }

    public function testTokenWithoutVersionClaimIsRejected(): void
    {
        $user = $this->createVerifiedUser('alice@example.com');
        // Encoded directly: going through the manager would let the listener add the claim back.
        $encoder = self::getContainer()->get(JWTEncoderInterface::class);
        $accessToken = $encoder->encode([
            'username' => $user->getUserIdentifier(),
            'roles' => $user->getRoles(),
            'iat' => time(),
            'exp' => time() + 900,
        ]);
        self::assertSame($user->getUserIdentifier(), $encoder->decode($accessToken)['username'] ?? null);

        self::assertSame(401, $this->statusWithAccessToken($accessToken));
    }

    private function jwtManager(): JWTTokenManagerInterface
    {
        return self::getContainer()->get(JWTTokenManagerInterface::class);
    }
}
