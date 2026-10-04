<?php

declare(strict_types=1);

namespace App\Notification\Push;

use App\Entity\User;
use App\Repository\Notification\PushSubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends a Web Push notification to every browser of a user (docs/NOTIFICATIONS.md), signed with
 * the server's VAPID keys. Free: the browsers' push services (Google, Mozilla, Apple, Microsoft)
 * need no account. A subscription the push service reports gone (404, 410) is deleted. Without
 * VAPID keys (not configured), nothing is sent.
 */
final class PushSender
{
    public function __construct(
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly EndpointPolicy $policy,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'push.client')]
        private readonly HttpClientInterface $client,
        #[Autowire('%env(VAPID_PUBLIC_KEY)%')]
        private readonly string $publicKey,
        #[Autowire('%env(VAPID_PRIVATE_KEY)%')]
        private readonly string $privateKey,
        #[Autowire('%env(VAPID_SUBJECT)%')]
        private readonly string $subject,
    ) {
    }

    public function isEnabled(): bool
    {
        return '' !== $this->publicKey && '' !== $this->privateKey && '' !== $this->subject;
    }

    public function publicKey(): ?string
    {
        return $this->isEnabled() ? $this->publicKey : null;
    }

    /**
     * @return int the browsers that accepted it
     */
    public function send(User $user, PushMessage $message): int
    {
        $targets = array_values(array_filter(
            $this->subscriptions->findByUser($user),
            fn ($subscription): bool => $this->policy->allows($subscription->getEndpoint()),
        ));
        if (!$this->isEnabled() || [] === $targets) {
            return 0;
        }

        $webPush = new WebPush(
            ['VAPID' => ['subject' => $this->subject, 'publicKey' => $this->publicKey, 'privateKey' => $this->privateKey]],
            ['TTL' => $message->ttlSeconds, 'urgency' => 'normal', 'topic' => substr(hash('sha256', $message->tag), 0, 32)],
            new Psr18Client($this->client),
            logger: $this->logger,
        );
        $byEndpoint = [];
        foreach ($targets as $subscription) {
            $byEndpoint[$subscription->getEndpoint()] = $subscription;
            $webPush->queueNotification(Subscription::create([
                'endpoint' => $subscription->getEndpoint(),
                'publicKey' => $subscription->getPublicKey(),
                'authToken' => $subscription->getAuthToken(),
                'contentEncoding' => 'aes128gcm',
            ]), $message->payload());
        }

        $delivered = 0;
        $now = $this->clock->now();
        foreach ($webPush->flush() as $report) {
            if (!$report instanceof MessageSentReport) {
                continue;
            }
            $subscription = $byEndpoint[$report->getEndpoint()] ?? null;
            if (null === $subscription) {
                continue;
            }
            if ($report->isSuccess()) {
                $subscription->delivered($now);
                ++$delivered;
            } elseif ($report->isSubscriptionExpired()) {
                $this->entityManager->remove($subscription);
            } else {
                $this->logger->warning('Web Push refused: {reason}', ['reason' => $report->getReason()]);
            }
        }
        $this->entityManager->flush();

        return $delivered;
    }
}
