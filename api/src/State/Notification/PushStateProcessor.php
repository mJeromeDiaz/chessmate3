<?php

declare(strict_types=1);

namespace App\State\Notification;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Notification\PushEndpointInput;
use App\ApiResource\Notification\PushState;
use App\ApiResource\Notification\PushSubscriptionInput;
use App\Notification\Push\PushSender;
use App\Notification\Push\SubscriptionManager;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Subscribing, checking and forgetting a browser. 409 when the server sends no Web Push (no VAPID
 * keys), 422 for an endpoint outside the push services or malformed keys.
 *
 * @implements ProcessorInterface<PushSubscriptionInput|PushEndpointInput, PushState>
 */
final class PushStateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly SubscriptionManager $subscriptions,
        private readonly PushSender $sender,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $notificationPushLimiter,
        private readonly RequestStack $requests,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PushState
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->notificationPushLimiter, $user->getId()->toRfc4122());
        $state = new PushState();
        $state->enabled = $this->sender->isEnabled();
        $state->publicKey = $this->sender->publicKey();

        if ($data instanceof PushSubscriptionInput) {
            if (!$state->enabled) {
                throw new ConflictHttpException('Web Push is not configured on this server.');
            }
            try {
                $this->subscriptions->subscribe(
                    $user,
                    $data->endpoint,
                    $data->keys['p256dh'] ?? '',
                    $data->keys['auth'] ?? '',
                    (string) $this->requests->getCurrentRequest()?->headers->get('User-Agent', ''),
                );
            } catch (\InvalidArgumentException $e) {
                throw new UnprocessableEntityHttpException($e->getMessage());
            }
            $state->subscribed = true;

            return $state;
        }
        if ('notification_push_unsubscribe' === $operation->getName()) {
            $this->subscriptions->unsubscribe($user, $data->endpoint);
            $state->subscribed = false;

            return $state;
        }
        $state->subscribed = $this->subscriptions->isSubscribed($user, $data->endpoint);

        return $state;
    }
}
