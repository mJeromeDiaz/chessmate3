<?php

declare(strict_types=1);

namespace App\Security\TrustedDevice;

use App\Entity\TrustedDevice;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Repository\TrustedDeviceRepository;
use App\Security\Audit\AuditLogger;
use App\Security\UserAgent\UserAgentSummarizer;
use Symfony\Component\Uid\Uuid;

/**
 * Trusted devices let a user skip the email 2FA step on a given browser for 30 days. Only the
 * SHA-256 hash of the cookie's random value is ever stored — same reasoning as the refresh token
 * ({@see \App\Entity\RefreshToken}): 256 bits of entropy makes the hash infeasible to invert, so a
 * leaked database copy cannot be replayed as a trusted-device cookie.
 */
final readonly class TrustedDeviceService
{
    private const TTL_DAYS = 30;

    public function __construct(
        private TrustedDeviceRepository $repository,
        private AuditLogger $auditLogger,
        private UserAgentSummarizer $userAgentSummarizer,
    ) {
    }

    public function issue(User $user, ?string $ip, ?string $userAgent): IssuedTrustedDevice
    {
        $plainToken = bin2hex(random_bytes(32));
        $expiresAt = new \DateTimeImmutable(sprintf('+%d days', self::TTL_DAYS));

        $device = new TrustedDevice(
            $user,
            $this->hash($plainToken),
            $this->userAgentSummarizer->summarize($userAgent),
            $expiresAt,
            $ip,
        );
        $this->repository->save($device);

        $this->auditLogger->log(AuditEventType::TrustedDeviceAdded, $user, ['label' => $device->getLabel()]);

        return new IssuedTrustedDevice($plainToken, $expiresAt);
    }

    /**
     * Finds the active device this token belongs to, but only if it belongs to $user — a token for
     * someone else's device must never grant a 2FA bypass on this account.
     */
    public function findActiveForUser(User $user, string $presentedToken): ?TrustedDevice
    {
        $device = $this->repository->findOneByTokenHash($this->hash($presentedToken));

        if (null === $device || $device->getUser()->getId()->equals($user->getId()) === false || !$device->isActive()) {
            return null;
        }

        return $device;
    }

    public function markUsed(TrustedDevice $device): void
    {
        $device->markUsedNow();
        $this->repository->save($device);
    }

    /**
     * @return list<TrustedDevice>
     */
    public function listActiveForUser(User $user): array
    {
        return array_values(array_filter(
            $this->repository->findBy(['user' => $user], ['createdAt' => 'DESC']),
            static fn (TrustedDevice $device): bool => $device->isActive(),
        ));
    }

    /**
     * @throws \DomainException if the device doesn't exist or doesn't belong to $user
     */
    public function revoke(User $user, Uuid $deviceId): void
    {
        $device = $this->repository->find($deviceId);

        if (null === $device || !$device->getUser()->getId()->equals($user->getId())) {
            throw new \DomainException('Trusted device not found.');
        }

        $device->revoke();
        $this->repository->save($device);

        $this->auditLogger->log(AuditEventType::TrustedDeviceRevoked, $user, ['label' => $device->getLabel()]);
    }

    /**
     * Revokes every trusted device for a user — called on password change/reset.
     */
    public function revokeAllForUser(User $user): void
    {
        foreach ($this->listActiveForUser($user) as $device) {
            $device->revoke();
            $this->repository->save($device);
        }
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
