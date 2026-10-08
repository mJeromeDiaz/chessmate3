<?php

declare(strict_types=1);

namespace App\ApiResource\EarlyAccess;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\ApiResource\Woodpecker\Set;
use App\Entity\EarlyAccess\AccessRequest as AccessRequestEntity;
use App\State\EarlyAccess\AccessRequestProcessor;
use App\State\EarlyAccess\AccessRequestProvider;

/**
 * A waiting-list request ("Demander l'accès"), for admins only (docs/EARLY_ACCESS.md). Inviting it
 * creates an invitation for its address; the key is only in that answer.
 *
 * @phpstan-type Invitation array{id: string, status: string, keyHint: string}
 */
#[ApiResource(
    shortName: 'EarlyAccessRequest',
    security: "is_granted('ROLE_ADMIN')",
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new GetCollection(
            uriTemplate: '/admin/access-requests',
            paginationItemsPerPage: 20,
            paginationMaximumItemsPerPage: 100,
            paginationClientItemsPerPage: true,
            openapi: new Operation(
                summary: 'Every request, in order of arrival.',
                parameters: [
                    new Parameter('invited', 'query', 'true: invited only; false: still waiting', schema: ['type' => 'boolean']),
                ],
            ),
            provider: AccessRequestProvider::class,
        ),
        new Post(
            uriTemplate: '/admin/access-requests/{id}/invite',
            requirements: ['id' => Set::UUID_PATTERN],
            status: 200,
            openapi: new Operation(summary: 'Invites the address (7 days): creates the invitation and emails its key, shown once in the answer. 409 if already invited.'),
            input: false,
            read: false,
            processor: AccessRequestProcessor::class,
            name: 'early_access_request_invite',
        ),
        new Delete(
            uriTemplate: '/admin/access-requests/{id}',
            requirements: ['id' => Set::UUID_PATTERN],
            openapi: new Operation(summary: 'Deletes the request (its invitation, if any, stays).'),
            read: false,
            processor: AccessRequestProcessor::class,
            name: 'early_access_request_delete',
        ),
    ],
)]
final class AccessRequest
{
    #[ApiProperty(identifier: true)]
    public string $id;
    public string $email;
    public \DateTimeImmutable $createdAt;
    public ?\DateTimeImmutable $invitedAt;
    /** The address already belongs to an account. */
    public bool $hasAccount;
    /** @var Invitation|null */
    public ?array $invitation;
    /** The invitation key: only in the answer to an invite. */
    public ?string $key = null;

    public static function from(AccessRequestEntity $request, \DateTimeImmutable $now, bool $hasAccount, ?string $key = null): self
    {
        $view = new self();
        $view->id = $request->getId()->toRfc4122();
        $view->email = $request->getEmail();
        $view->createdAt = $request->getCreatedAt();
        $view->invitedAt = $request->getInvitedAt();
        $view->hasAccount = $hasAccount;
        $invitation = $request->getInvitation();
        $view->invitation = null === $invitation ? null : [
            'id' => $invitation->getId()->toRfc4122(),
            'status' => $invitation->getStatus($now)->value,
            'keyHint' => $invitation->getKeyHint(),
        ];
        $view->key = $key;

        return $view;
    }
}
