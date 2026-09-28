<?php

declare(strict_types=1);

namespace App\Security\Audit;

use App\Entity\AuditLogEntry;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Repository\AuditLogEntryRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Records security events for the audit log.
 *
 * The only place allowed to write an {@see AuditLogEntry}: every caller goes through
 * {@see self::log()}, which is the one spot that has to be trusted never to receive a secret in
 * `$metadata` (a password, a 2FA code, a token). A failure to write the log is swallowed rather than
 * propagated, since an audit trail issue must never be what breaks a login.
 */
final readonly class AuditLogger
{
    public function __construct(
        private AuditLogEntryRepository $repository,
        private RequestStack $requestStack,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * IP and user agent come from the current request. Code running outside of one (a Messenger
     * handler) passes the ones captured from the originating request instead.
     *
     * @param array<string, mixed> $metadata never a password, code, or token
     */
    public function log(AuditEventType $eventType, ?User $user = null, array $metadata = [], ?string $ip = null, ?string $userAgent = null): void
    {
        $request = $this->requestStack->getCurrentRequest();

        $entry = new AuditLogEntry(
            $eventType,
            $user,
            $ip ?? $request?->getClientIp(),
            $userAgent ?? $request?->headers->get('User-Agent'),
            $metadata,
        );

        try {
            $this->repository->save($entry);
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to write audit log entry "{event}".', [
                'event' => $eventType->value,
                'exception' => $exception,
            ]);
        }
    }
}
