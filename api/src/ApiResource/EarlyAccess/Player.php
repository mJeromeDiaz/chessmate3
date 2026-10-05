<?php

declare(strict_types=1);

namespace App\ApiResource\EarlyAccess;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\EarlyAccess\Stats\CommunityActivity;
use App\EarlyAccess\Stats\PlayerFacts;
use App\Entity\User;
use App\ApiResource\Woodpecker\Set;
use App\State\EarlyAccess\PlayerActionProcessor;
use App\State\EarlyAccess\PlayerProvider;

/**
 * A player, as the admin dashboard lists them (docs/EARLY_ACCESS.md): the account, how it signed
 * up, and how much it plays.
 *
 * @phpstan-import-type Facts from PlayerFacts
 * @phpstan-import-type Lichess from PlayerFacts
 */
#[ApiResource(
    shortName: 'EarlyAccessPlayer',
    security: "is_granted('ROLE_ADMIN')",
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new GetCollection(
            uriTemplate: '/admin/users',
            paginationItemsPerPage: 20,
            paginationMaximumItemsPerPage: 100,
            paginationClientItemsPerPage: true,
            openapi: new Operation(
                summary: 'Every account, newest first.',
                parameters: [
                    new Parameter('search', 'query', 'Part of the email, handle or display name', schema: ['type' => 'string']),
                    new Parameter('suspended', 'query', 'true: suspended accounts only; false: the others', schema: ['type' => 'boolean']),
                ],
            ),
            provider: PlayerProvider::class,
        ),
        new Post(
            uriTemplate: '/admin/users/{id}/suspend',
            requirements: ['id' => Set::UUID_PATTERN],
            status: 200,
            openapi: new Operation(summary: 'Suspends the account: every session is closed and no new one can open. Idempotent; 409 for your own account or an admin.'),
            input: SuspendInput::class,
            read: false,
            processor: PlayerActionProcessor::class,
            name: 'early_access_player_suspend',
        ),
        new Post(
            uriTemplate: '/admin/users/{id}/unsuspend',
            requirements: ['id' => Set::UUID_PATTERN],
            status: 200,
            openapi: new Operation(summary: 'Lifts the suspension: the player can sign in again. Idempotent.'),
            input: false,
            read: false,
            processor: PlayerActionProcessor::class,
            name: 'early_access_player_unsuspend',
        ),
    ],
)]
final class Player
{
    #[ApiProperty(identifier: true)]
    public string $id;
    public ?string $email;
    public ?string $displayName;
    public ?string $handle;
    public \DateTimeImmutable $createdAt;
    public bool $emailVerified;
    public bool $isAdmin;
    /** password, google, lichess, or other (opened without an invitation) */
    public string $signupMethod;
    /** The first characters of the invitation key it signed up with. */
    public ?string $keyHint;
    public ?\DateTimeImmutable $lastActivityAt;
    /** At least one exercise in the last 7 days. */
    public bool $active;
    /** Time played in the last 30 days. */
    public int $trainingMs30d;
    /** @var Lichess|null null: no Lichess account linked */
    public ?array $lichess;
    public ?\DateTimeImmutable $deletionScheduledAt;
    /** Suspended by an admin (null: not suspended). */
    public ?\DateTimeImmutable $suspendedAt;
    /** The admin's internal note on the suspension. */
    public ?string $suspensionReason;

    /**
     * @param Facts $facts
     */
    public static function from(User $user, array $facts, \DateTimeImmutable $now): self
    {
        $view = new self();
        $view->id = $user->getId()->toRfc4122();
        $view->email = $user->getEmail();
        $view->displayName = $user->getDisplayName();
        $view->handle = $user->getHandle();
        $view->createdAt = $user->getCreatedAt();
        $view->emailVerified = $user->isEmailVerified();
        $view->isAdmin = \in_array('ROLE_ADMIN', $user->getRoles(), true);
        $view->signupMethod = $facts['signupMethod'];
        $view->keyHint = $facts['keyHint'];
        $view->lastActivityAt = $facts['lastActivityAt'];
        $view->active = null !== $facts['lastActivityAt'] && $facts['lastActivityAt'] >= CommunityActivity::activeSince($now);
        $view->trainingMs30d = $facts['trainingMs30d'];
        $view->lichess = $facts['lichess'];
        $view->deletionScheduledAt = $user->getDeletionScheduledAt();
        $view->suspendedAt = $user->getSuspendedAt();
        $view->suspensionReason = $user->getSuspensionReason();

        return $view;
    }
}
