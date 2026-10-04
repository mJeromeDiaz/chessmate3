<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A browser's subscription keys, real enough for Web Push encryption, and a fake push service.
 */
trait PushTestTrait
{
    /** @var list<array{url: string, headers: list<string>}> requests the fake push service received */
    protected array $pushRequests = [];

    /** What the fake push service answers: 201 delivered, 410 gone. */
    protected int $pushStatus = 201;

    /**
     * @return array{p256dh: string, auth: string} what PushSubscription.toJSON().keys holds
     */
    protected static function browserKeys(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => \OPENSSL_KEYTYPE_EC]);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        /** @var array{x: string, y: string} $ec */
        $ec = $details['ec'];
        $point = "\x04".str_pad($ec['x'], 32, "\0", \STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", \STR_PAD_LEFT);

        return ['p256dh' => self::base64url($point), 'auth' => self::base64url(random_bytes(16))];
    }

    protected static function endpoint(string $id): string
    {
        return 'https://fcm.googleapis.com/fcm/send/'.$id;
    }

    /**
     * Replaces the push service (call it before the test's first request): answers $pushStatus and
     * records each request. No test may ever reach a real push service.
     */
    protected function fakePushService(): void
    {
        // One kernel for the whole test: a reboot between requests would bring the real client back.
        $this->client->disableReboot();
        self::getContainer()->set('push.client', new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            /** @var list<string> $headers */
            $headers = $options['headers'] ?? [];
            $this->pushRequests[] = ['url' => $url, 'headers' => $headers];

            return new MockResponse('', ['http_code' => $this->pushStatus]);
        }));
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
