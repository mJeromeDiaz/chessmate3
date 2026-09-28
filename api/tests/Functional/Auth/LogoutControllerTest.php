<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

final class LogoutControllerTest extends AuthWebTestCase
{
    public function testLogoutRevokesTheSessionSoTheOldCookieCanNoLongerRefresh(): void
    {
        $this->createVerifiedUser('alice@example.com', 'CorrectHorseBatteryStaple9!');
        $this->loginAndGetRefreshCookie('alice@example.com', 'CorrectHorseBatteryStaple9!');

        $this->client->request('POST', '/api/auth/logout');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', '/api/auth/refresh', server: ['HTTP_X-Refresh-Request' => '1']);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testLogoutWithoutAnySessionStillSucceeds(): void
    {
        $this->client->request('POST', '/api/auth/logout');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }
}
