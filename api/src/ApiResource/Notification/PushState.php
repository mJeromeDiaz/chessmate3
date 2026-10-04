<?php

declare(strict_types=1);

namespace App\ApiResource\Notification;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\NotExposed;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\State\Notification\PushStateProcessor;
use App\State\Notification\PushStateProvider;

/**
 * Web Push for the current user (docs/NOTIFICATIONS.md): whether the server sends any, its VAPID
 * public key (what the browser subscribes with), and whether a given browser is subscribed.
 */
#[ApiResource(
    shortName: 'NotificationPush',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(
            uriTemplate: '/notifications/push',
            openapi: new Operation(summary: 'Whether Web Push is available, and its public key.'),
            provider: PushStateProvider::class,
            name: 'notification_push_config',
        ),
        new Post(
            uriTemplate: '/notifications/push/subscriptions',
            status: 200,
            openapi: new Operation(summary: 'Subscribes this browser (or moves its subscription to the current user).'),
            input: PushSubscriptionInput::class,
            processor: PushStateProcessor::class,
            name: 'notification_push_subscribe',
        ),
        new Post(
            uriTemplate: '/notifications/push/subscription-status',
            status: 200,
            openapi: new Operation(summary: 'Whether this browser\'s endpoint is subscribed for the current user.'),
            input: PushEndpointInput::class,
            processor: PushStateProcessor::class,
            name: 'notification_push_status',
        ),
        new Post(
            uriTemplate: '/notifications/push/unsubscribe',
            status: 200,
            openapi: new Operation(summary: 'Forgets this browser.'),
            input: PushEndpointInput::class,
            processor: PushStateProcessor::class,
            name: 'notification_push_unsubscribe',
        ),
        new NotExposed(uriTemplate: '/notifications/push/{id}', requirements: ['id' => 'state']),
    ],
)]
final class PushState
{
    #[ApiProperty(identifier: true)]
    public string $id = 'state';
    public bool $enabled;
    public ?string $publicKey;
    /** For the browser given (subscribe, status): subscribed for this user. */
    public ?bool $subscribed = null;
}
