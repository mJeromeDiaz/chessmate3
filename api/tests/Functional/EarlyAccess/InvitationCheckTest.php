<?php

declare(strict_types=1);

namespace App\Tests\Functional\EarlyAccess;

use App\Entity\EarlyAccess\InvitationLog;
use Symfony\Component\HttpFoundation\Response;

/**
 * The sign-up page checks its invitation link (docs/EARLY_ACCESS.md).
 */
final class InvitationCheckTest extends EarlyAccessWebTestCase
{
    public function testAPendingKeyIsValid(): void
    {
        $invitation = $this->invite();

        $response = $this->check($invitation['key']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['expiresAt' => '2026-10-12T10:00:00+00:00'], $this->json($response));
    }

    public function testAKeyThatNeverExpiresIsValid(): void
    {
        $invitation = $this->invite(body: ['neverExpires' => true]);

        self::assertSame(['expiresAt' => null], $this->json($this->check($invitation['key'])));
    }

    public function testAnExpiredKeyIsReportedWithoutBeingLogged(): void
    {
        $invitation = $this->invite();
        $this->clock->modify('+8 days');
        $logs = $this->entityManager->getRepository(InvitationLog::class)->count([]);

        $response = $this->check($invitation['key']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('invitation_expired', $this->json($response)['error'] ?? null);
        self::assertSame($logs, $this->entityManager->getRepository(InvitationLog::class)->count([]));
    }

    public function testRevokedUnknownMalformedAndMissingKeys(): void
    {
        $invitation = $this->invite();
        self::assertSame(204, $this->api('DELETE', '/api/admin/invitation-keys/'.$invitation['id'], $this->admin)->getStatusCode());

        self::assertSame('invitation_invalid', $this->json($this->check($invitation['key']))['error'] ?? null);
        self::assertSame('invitation_invalid', $this->json($this->check(str_repeat('A', 32)))['error'] ?? null);
        self::assertSame('invitation_invalid', $this->json($this->check('short'))['error'] ?? null);
        self::assertSame('invitation_required', $this->json($this->check(null))['error'] ?? null);
    }

    public function testChecksAreRateLimitedByIp(): void
    {
        for ($i = 0; $i < 30; ++$i) {
            self::assertSame(422, $this->check(str_repeat('A', 32))->getStatusCode());
        }

        self::assertSame(429, $this->check(str_repeat('A', 32))->getStatusCode());
    }

    private function check(?string $key): Response
    {
        $this->client->request('POST', '/api/auth/invitation/check', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['key' => $key], \JSON_THROW_ON_ERROR));

        return $this->client->getResponse();
    }
}
