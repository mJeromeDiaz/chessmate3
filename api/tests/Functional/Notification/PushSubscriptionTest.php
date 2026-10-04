<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification;

use App\Entity\Notification\PushSubscription;
use App\Notification\Push\PushMessage;
use App\Notification\Push\PushSender;
use App\Repository\Notification\PushSubscriptionRepository;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;

/**
 * Web Push subscriptions (docs/NOTIFICATIONS.md): public key, one subscription per browser, push
 * services only (no SSRF), and sending: encrypted, VAPID-signed, gone subscriptions dropped.
 */
final class PushSubscriptionTest extends WoodpeckerWebTestCase
{
    use PushTestTrait;

    public function testTheServerGivesItsPublicKey(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $config = $this->json($this->api('GET', '/api/notifications/push', $alice));

        self::assertTrue($config['enabled'] ?? null);
        self::assertIsString($config['publicKey'] ?? null);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{80,}$/', $config['publicKey']);
    }

    public function testABrowserSubscribesChecksAndUnsubscribes(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $endpoint = self::endpoint('alice-laptop');

        $subscribed = $this->api('POST', '/api/notifications/push/subscriptions', $alice, ['endpoint' => $endpoint, 'keys' => self::browserKeys()]);
        self::assertSame(200, $subscribed->getStatusCode(), (string) $subscribed->getContent());
        self::assertTrue($this->isSubscribed($alice, $endpoint));
        self::assertFalse($this->isSubscribed($alice, self::endpoint('elsewhere')));

        $this->api('POST', '/api/notifications/push/unsubscribe', $alice, ['endpoint' => $endpoint]);
        self::assertFalse($this->isSubscribed($alice, $endpoint));
    }

    public function testTheSameBrowserMovesToTheAccountSignedIn(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $bob = $this->createUserIn('bob@example.com');
        $endpoint = self::endpoint('shared-browser');

        $this->api('POST', '/api/notifications/push/subscriptions', $alice, ['endpoint' => $endpoint, 'keys' => self::browserKeys()]);
        $this->api('POST', '/api/notifications/push/subscriptions', $bob, ['endpoint' => $endpoint, 'keys' => self::browserKeys()]);

        self::assertFalse($this->isSubscribed($alice, $endpoint));
        self::assertTrue($this->isSubscribed($bob, $endpoint));
        // Alice cannot remove Bob's browser.
        $this->api('POST', '/api/notifications/push/unsubscribe', $alice, ['endpoint' => $endpoint]);
        self::assertTrue($this->isSubscribed($bob, $endpoint));
    }

    public function testOnlyThePushServicesAreAccepted(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        foreach ([
            'http://fcm.googleapis.com/fcm/send/x',
            'https://fcm.googleapis.com:8443/fcm/send/x',
            'https://user@fcm.googleapis.com/fcm/send/x',
            'https://evil.example/fcm/send/x',
            'https://fcm.googleapis.com.evil.example/x',
            'https://169.254.169.254/latest/meta-data',
            'https://localhost/x',
        ] as $endpoint) {
            $response = $this->api('POST', '/api/notifications/push/subscriptions', $alice, ['endpoint' => $endpoint, 'keys' => self::browserKeys()]);
            self::assertSame(422, $response->getStatusCode(), $endpoint);
        }
        foreach (['https://web.push.apple.com/QGuQ', 'https://updates.push.services.mozilla.com/wpush/v2/x', 'https://wns2-par02p.notify.windows.com/w/?token=x'] as $endpoint) {
            $response = $this->api('POST', '/api/notifications/push/subscriptions', $alice, ['endpoint' => $endpoint, 'keys' => self::browserKeys()]);
            self::assertSame(200, $response->getStatusCode(), $endpoint);
        }
        $bad = $this->api('POST', '/api/notifications/push/subscriptions', $alice, ['endpoint' => self::endpoint('k'), 'keys' => ['p256dh' => 'short', 'auth' => '!!']]);
        self::assertSame(422, $bad->getStatusCode());
    }

    public function testSendingEncryptsSignsAndDropsGoneBrowsers(): void
    {
        $this->fakePushService();
        $alice = $this->createUserIn('alice@example.com');
        foreach (['laptop', 'phone'] as $device) {
            $this->api('POST', '/api/notifications/push/subscriptions', $alice, ['endpoint' => self::endpoint($device), 'keys' => self::browserKeys()]);
        }
        $sender = self::getContainer()->get(PushSender::class);

        $delivered = $sender->send($alice, new PushMessage('Mardi soir', 'Ta session commence à 18:30.', '/#/session', 'reminder', 1800));

        self::assertSame(2, $delivered);
        self::assertCount(2, $this->pushRequests);
        $headers = implode("\n", $this->pushRequests[0]['headers']);
        self::assertStringContainsString('Authorization: vapid t=', $headers);
        self::assertStringContainsString('Content-Encoding: aes128gcm', $headers);
        self::assertStringContainsString('TTL: 1800', $headers);

        $this->pushStatus = 410;
        self::assertSame(0, $sender->send($alice, new PushMessage('x', 'y', '/', 't', 60)));
        self::assertSame(0, self::getContainer()->get(PushSubscriptionRepository::class)->count([]), 'Gone browsers are forgotten.');
    }

    public function testAnotherUsersBrowsersAreNeverReached(): void
    {
        $this->fakePushService();
        $alice = $this->createUserIn('alice@example.com');
        $bob = $this->createUserIn('bob@example.com');
        $this->api('POST', '/api/notifications/push/subscriptions', $bob, ['endpoint' => self::endpoint('bob'), 'keys' => self::browserKeys()]);

        self::assertSame(0, self::getContainer()->get(PushSender::class)->send($alice, new PushMessage('x', 'y', '/', 't', 60)));
        self::assertSame([], $this->pushRequests);
        self::assertSame(64, \strlen(PushSubscription::hash('x')));
    }

    private function isSubscribed(\App\Entity\User $user, string $endpoint): bool
    {
        $state = $this->json($this->api('POST', '/api/notifications/push/subscription-status', $user, ['endpoint' => $endpoint]));

        return true === ($state['subscribed'] ?? null);
    }
}
