<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\RefreshToken;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\AuditLogEntryRepository;
use App\Repository\RefreshTokenRepository;
use App\Repository\UserRepository;
use App\Security\Audit\AuditLogger;
use App\Security\RefreshToken\Exception\RefreshTokenExpiredException;
use App\Security\RefreshToken\Exception\RefreshTokenNotFoundException;
use App\Security\RefreshToken\Exception\RefreshTokenReuseDetectedException;
use App\Security\RefreshToken\RefreshTokenService;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

final class RefreshTokenServiceTest extends TestCase
{
    private const IDLE_TTL = 7 * 86400;
    private const ABSOLUTE_TTL = 30 * 86400;

    private RefreshTokenManagerInterface&MockObject $manager;
    private RefreshTokenRepository&MockObject $repository;
    private UserRepository&MockObject $userRepository;
    private User $user;
    private RefreshTokenService $service;

    /** @var list<RefreshToken> */
    private array $saved = [];

    protected function setUp(): void
    {
        $this->user = new User();

        $generator = $this->createStub(RefreshTokenGeneratorInterface::class);
        $generator->method('createForUserWithTtl')->willReturnCallback(
            static fn (User $user, int $ttl): RefreshToken => RefreshToken::createForUserWithTtl(bin2hex(random_bytes(64)), $user, $ttl),
        );

        $this->manager = $this->createMock(RefreshTokenManagerInterface::class);
        $this->manager->method('save')->willReturnCallback(function (RefreshToken $token): void {
            $this->saved[] = $token;
        });

        $this->repository = $this->createMock(RefreshTokenRepository::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->userRepository->method('findOneByUuid')->willReturnCallback(
            fn (Uuid $id): ?User => $id->equals($this->user->getId()) ? $this->user : null,
        );

        $this->service = new RefreshTokenService(
            $generator,
            $this->manager,
            $this->repository,
            $this->userRepository,
            new AuditLogger($this->createStub(AuditLogEntryRepository::class), new RequestStack(), new NullLogger()),
            self::IDLE_TTL,
            self::ABSOLUTE_TTL,
        );
    }

    public function testNewFamilyGetsTheIdleTimeoutAndTheAbsoluteExpiry(): void
    {
        $issued = $this->service->issueNewFamily($this->user);

        self::assertSame(128, \strlen($issued->plainToken));
        self::assertEqualsWithDelta(time() + self::IDLE_TTL, $issued->expiresAt->getTimestamp(), 2);

        $stored = $this->saved[0];
        self::assertEqualsWithDelta(time() + self::ABSOLUTE_TTL, $stored->getFamilyExpiresAt()->getTimestamp(), 2);
        self::assertSame($this->user->getUserIdentifier(), $stored->getUsername());
    }

    public function testEachLoginStartsADistinctFamily(): void
    {
        $this->service->issueNewFamily($this->user);
        $this->service->issueNewFamily($this->user);

        self::assertFalse($this->saved[0]->getFamilyId()->equals($this->saved[1]->getFamilyId()));
    }

    public function testRotationRevokesThePresentedTokenAndCarriesTheFamilyForward(): void
    {
        $presented = $this->token();
        $this->manager->method('get')->with('presented')->willReturn($presented);
        $this->repository->expects(self::once())->method('revokeIfActive')->with($presented)->willReturn(1);
        $this->repository->expects(self::never())->method('revokeFamily');

        $result = $this->service->rotate('presented');

        self::assertSame($this->user, $result->user);
        $replacement = $this->saved[0];
        self::assertTrue($replacement->getFamilyId()->equals($presented->getFamilyId()));
        self::assertEquals($presented->getFamilyExpiresAt(), $replacement->getFamilyExpiresAt());
        self::assertNotSame('presented', $result->refreshToken->plainToken);
    }

    public function testRotationIsCappedByTheFamilysAbsoluteExpiry(): void
    {
        $familyExpiresAt = new \DateTimeImmutable('+1 hour');
        $this->manager->method('get')->willReturn($this->token(familyExpiresAt: $familyExpiresAt));
        $this->repository->method('revokeIfActive')->willReturn(1);

        $result = $this->service->rotate('presented');

        self::assertEqualsWithDelta($familyExpiresAt->getTimestamp(), $result->refreshToken->expiresAt->getTimestamp(), 2);
    }

    public function testRotationAtTheFamilysAbsoluteExpiryIssuesNothing(): void
    {
        // Token still inside its own window, but its family has just run out.
        $this->manager->method('get')->willReturn($this->token(familyExpiresAt: new \DateTimeImmutable('-1 second')));
        $this->repository->method('revokeIfActive')->willReturn(1);

        $this->expectException(RefreshTokenExpiredException::class);

        try {
            $this->service->rotate('presented');
        } finally {
            self::assertSame([], $this->saved);
        }
    }

    public function testReplayingARevokedTokenRevokesTheFamilyAndBumpsTheTokenVersion(): void
    {
        $presented = $this->token(revoked: true);
        $this->manager->method('get')->willReturn($presented);
        $this->repository->expects(self::once())->method('revokeFamily')->with($presented->getFamilyId());
        $this->repository->expects(self::never())->method('revokeIfActive');
        $this->userRepository->expects(self::once())->method('save')->with($this->user);

        $this->expectException(RefreshTokenReuseDetectedException::class);

        try {
            $this->service->rotate('presented');
        } finally {
            self::assertSame(1, $this->user->getTokenVersion());
            self::assertSame([], $this->saved);
        }
    }

    public function testLosingAConcurrentRotationIsTreatedAsReuse(): void
    {
        $presented = $this->token();
        $this->manager->method('get')->willReturn($presented);
        $this->repository->method('revokeIfActive')->willReturn(0);
        $this->repository->expects(self::once())->method('revokeFamily')->with($presented->getFamilyId());

        $this->expectException(RefreshTokenReuseDetectedException::class);

        $this->service->rotate('presented');
    }

    public function testExpiredTokenIsRejectedWithoutRevokingAnything(): void
    {
        $this->manager->method('get')->willReturn($this->token(validFor: '-1 second'));
        $this->repository->expects(self::never())->method('revokeIfActive');
        $this->repository->expects(self::never())->method('revokeFamily');

        $this->expectException(RefreshTokenExpiredException::class);

        $this->service->rotate('presented');
    }

    public function testUnknownTokenIsRejected(): void
    {
        $this->manager->method('get')->willReturn(null);

        $this->expectException(RefreshTokenNotFoundException::class);

        $this->service->rotate('unknown');
    }

    public function testTokenOfADeletedUserIsRejected(): void
    {
        $orphan = RefreshToken::createForUserWithTtl('presented', new User(), 3600);
        $orphan->setFamilyId(Uuid::v7())->setFamilyExpiresAt(new \DateTimeImmutable('+1 day'));
        $this->manager->method('get')->willReturn($orphan);
        $this->repository->method('revokeIfActive')->willReturn(1);

        $this->expectException(RefreshTokenNotFoundException::class);

        $this->service->rotate('presented');
    }

    public function testRevokeAllSessionsTargetsTheUser(): void
    {
        $this->repository->expects(self::once())->method('revokeAllForUser')->with($this->user->getUserIdentifier());

        $this->service->revokeAllSessions($this->user);
    }

    private function token(string $validFor = '+1 day', ?\DateTimeImmutable $familyExpiresAt = null, bool $revoked = false): RefreshToken
    {
        $token = RefreshToken::createForUserWithTtl('presented', $this->user, 0);
        $token->setValid(new \DateTime($validFor));
        $token->setFamilyId(Uuid::v7());
        $token->setFamilyExpiresAt($familyExpiresAt ?? new \DateTimeImmutable('+20 days'));

        if ($revoked) {
            (new \ReflectionProperty(RefreshToken::class, 'revokedAt'))->setValue($token, new \DateTimeImmutable('-1 minute'));
        }

        return $token;
    }
}
