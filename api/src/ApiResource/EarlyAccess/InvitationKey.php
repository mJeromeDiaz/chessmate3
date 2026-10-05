<?php

declare(strict_types=1);

namespace App\ApiResource\EarlyAccess;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\ApiResource\Woodpecker\Set;
use App\Entity\EarlyAccess\InvitationKey as InvitationKeyEntity;
use App\Entity\EarlyAccess\InvitationLog;
use App\Entity\User;
use App\State\EarlyAccess\CreateInvitationProcessor;
use App\State\EarlyAccess\InvitationActionProcessor;
use App\State\EarlyAccess\InvitationProvider;

/**
 * An early access invitation, for admins only (docs/EARLY_ACCESS.md). The key itself is only in
 * the answer that created it (creation or resend): the server keeps its sha256.
 *
 * @phpstan-type Account array{id: string, email: string|null, handle: string|null}
 * @phpstan-type LogLine array{id: string, action: string, actor: Account|null, details: array<string, mixed>, createdAt: string}
 */
#[ApiResource(
    shortName: 'EarlyAccessInvitationKey',
    security: "is_granted('ROLE_ADMIN')",
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new GetCollection(
            uriTemplate: '/admin/invitation-keys',
            paginationItemsPerPage: 20,
            paginationMaximumItemsPerPage: 100,
            paginationClientItemsPerPage: true,
            openapi: new Operation(
                summary: 'Every invitation, newest first.',
                parameters: [
                    new Parameter('status', 'query', 'pending, used, expired or revoked', schema: ['type' => 'string', 'enum' => ['pending', 'used', 'expired', 'revoked']]),
                    new Parameter('email', 'query', 'Part of the invited address', schema: ['type' => 'string']),
                    new Parameter('createdAfter', 'query', 'ISO 8601 instant (included)', schema: ['type' => 'string', 'format' => 'date-time']),
                    new Parameter('createdBefore', 'query', 'ISO 8601 instant (excluded)', schema: ['type' => 'string', 'format' => 'date-time']),
                ],
            ),
            provider: InvitationProvider::class,
        ),
        new Get(
            uriTemplate: '/admin/invitation-keys/{id}',
            requirements: ['id' => Set::UUID_PATTERN],
            openapi: new Operation(summary: 'One invitation and its log.'),
            provider: InvitationProvider::class,
        ),
        new Post(
            uriTemplate: '/admin/invitation-keys',
            openapi: new Operation(summary: 'Creates an invitation and emails its key (shown once in the answer).'),
            input: CreateInvitationInput::class,
            processor: CreateInvitationProcessor::class,
        ),
        new Post(
            uriTemplate: '/admin/invitation-keys/{id}/resend',
            requirements: ['id' => Set::UUID_PATTERN],
            status: 200,
            openapi: new Operation(summary: 'A new key on the invitation, emailed again; the previous key stops working.'),
            input: false,
            read: false,
            processor: InvitationActionProcessor::class,
            name: 'early_access_invitation_resend',
        ),
        new Delete(
            uriTemplate: '/admin/invitation-keys/{id}',
            requirements: ['id' => Set::UUID_PATTERN],
            openapi: new Operation(summary: 'Revokes the invitation (never deleted). Idempotent; 409 once used.'),
            read: false,
            processor: InvitationActionProcessor::class,
            name: 'early_access_invitation_revoke',
        ),
    ],
)]
final class InvitationKey
{
    #[ApiProperty(identifier: true)]
    public string $id;
    public string $email;
    /** pending, used, expired or revoked */
    public string $status;
    /** The key: only right after a creation or a resend. */
    public ?string $key = null;
    /** Its first characters, to tell keys apart. */
    public string $keyHint;
    public \DateTimeImmutable $createdAt;
    /** @var Account|null */
    public ?array $createdBy;
    public ?\DateTimeImmutable $expiresAt;
    public ?\DateTimeImmutable $usedAt;
    /** @var Account|null */
    public ?array $usedBy;
    public ?\DateTimeImmutable $revokedAt;
    /** pending, sent or failed: the email of the current key */
    public string $emailStatus;
    public ?\DateTimeImmutable $emailSentAt;
    public int $sendCount;
    /** @var list<LogLine>|null only on the detail */
    public ?array $logs = null;

    /**
     * @param list<InvitationLog>|null $logs
     */
    public static function from(InvitationKeyEntity $invitation, \DateTimeImmutable $now, ?string $key = null, ?array $logs = null): self
    {
        $view = new self();
        $view->id = $invitation->getId()->toRfc4122();
        $view->email = $invitation->getEmail();
        $view->status = $invitation->getStatus($now)->value;
        $view->key = $key;
        $view->keyHint = $invitation->getKeyHint();
        $view->createdAt = $invitation->getCreatedAt();
        $view->createdBy = self::account($invitation->getCreatedBy());
        $view->expiresAt = $invitation->getExpiresAt();
        $view->usedAt = $invitation->getUsedAt();
        $view->usedBy = self::account($invitation->getUsedBy());
        $view->revokedAt = $invitation->getRevokedAt();
        $view->emailStatus = $invitation->getEmailStatus()->value;
        $view->emailSentAt = $invitation->getEmailSentAt();
        $view->sendCount = $invitation->getSendCount();
        $view->logs = null === $logs ? null : array_map(static fn (InvitationLog $log): array => [
            'id' => $log->getId()->toRfc4122(),
            'action' => $log->getAction()->value,
            'actor' => self::account($log->getActor()),
            'details' => $log->getDetails(),
            'createdAt' => $log->getCreatedAt()->format(\DATE_ATOM),
        ], $logs);

        return $view;
    }

    /**
     * @return Account|null
     */
    public static function account(?User $user): ?array
    {
        return null === $user ? null : [
            'id' => $user->getId()->toRfc4122(),
            'email' => $user->getEmail(),
            'handle' => $user->getHandle(),
        ];
    }
}
