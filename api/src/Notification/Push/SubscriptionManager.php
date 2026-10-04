<?php

declare(strict_types=1);

namespace App\Notification\Push;

use App\Entity\Notification\PushSubscription;
use App\Entity\User;
use App\Repository\Notification\PushSubscriptionRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Browser subscriptions to Web Push (docs/NOTIFICATIONS.md): one per endpoint, moved to the
 * account signed in on that browser; at most a few per user.
 */
final class SubscriptionManager
{
    public const MAX_PER_USER = 10;
    private const KEY_PATTERN = '/^[A-Za-z0-9_-]+={0,2}$/';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly EndpointPolicy $policy,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws \InvalidArgumentException an endpoint outside the push services, or malformed keys
     */
    public function subscribe(User $user, string $endpoint, string $publicKey, string $authToken, string $userAgent): PushSubscription
    {
        if (!$this->policy->allows($endpoint)) {
            throw new \InvalidArgumentException('This endpoint is not a known push service.');
        }
        if (1 !== preg_match(self::KEY_PATTERN, $publicKey) || \strlen($publicKey) > 128 || \strlen($publicKey) < 80
            || 1 !== preg_match(self::KEY_PATTERN, $authToken) || \strlen($authToken) > 64 || \strlen($authToken) < 16) {
            throw new \InvalidArgumentException('Malformed subscription keys.');
        }

        $existing = $this->subscriptions->findByEndpoint($endpoint);
        if (null !== $existing) {
            $existing->renew($user, $publicKey, $authToken, $userAgent);
            $this->entityManager->flush();

            return $existing;
        }
        // The oldest browsers make room: a user rarely has more than a few.
        $mine = $this->subscriptions->findByUser($user);
        foreach (\array_slice($mine, 0, max(0, \count($mine) - self::MAX_PER_USER + 1)) as $old) {
            $this->entityManager->remove($old);
        }
        $subscription = new PushSubscription($user, $endpoint, $publicKey, $authToken, $userAgent, $this->clock->now());
        $this->entityManager->persist($subscription);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Subscribed twice at once: the other request created it.
            throw new \InvalidArgumentException('Subscription already being created.');
        }

        return $subscription;
    }

    /**
     * Forgets this browser for this user (nothing when it is unknown or another user's).
     */
    public function unsubscribe(User $user, string $endpoint): void
    {
        $subscription = $this->subscriptions->findByEndpoint($endpoint);
        if (null !== $subscription && $subscription->getUser()->getId()->equals($user->getId())) {
            $this->entityManager->remove($subscription);
            $this->entityManager->flush();
        }
    }

    public function isSubscribed(User $user, string $endpoint): bool
    {
        $subscription = $this->subscriptions->findByEndpoint($endpoint);

        return null !== $subscription && $subscription->getUser()->getId()->equals($user->getId());
    }
}
