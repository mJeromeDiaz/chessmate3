<?php

declare(strict_types=1);

namespace App\Tests\Unit\EarlyAccess;

use App\Entity\EarlyAccess\InvitationKey;
use App\Entity\User;
use App\Enum\EarlyAccess\EmailStatus;
use App\Enum\EarlyAccess\InvitationStatus;
use PHPUnit\Framework\TestCase;

final class InvitationKeyTest extends TestCase
{
    public function testTheStatusFollowsTheDates(): void
    {
        $now = new \DateTimeImmutable('2026-10-05 10:00:00');
        $invitation = new InvitationKey('guest@example.com', str_repeat('a', 64), 'Abcd', new User(), $now, $now->modify('+7 days'));

        self::assertSame(InvitationStatus::Pending, $invitation->getStatus($now->modify('+7 days -1 second')));
        self::assertSame(InvitationStatus::Expired, $invitation->getStatus($now->modify('+7 days')));

        $invitation->revoke($now->modify('+1 day'));
        $invitation->revoke($now->modify('+2 days'));
        self::assertEquals($now->modify('+1 day'), $invitation->getRevokedAt(), 'The first revocation stays.');
        self::assertSame(InvitationStatus::Revoked, $invitation->getStatus($now->modify('+8 days')));
    }

    public function testWithoutExpiryItStaysPendingAndARenewalQueuesTheEmailAgain(): void
    {
        $now = new \DateTimeImmutable('2026-10-05 10:00:00');
        $invitation = new InvitationKey('guest@example.com', str_repeat('a', 64), 'Abcd', new User(), $now, null);
        self::assertSame(InvitationStatus::Pending, $invitation->getStatus($now->modify('+10 years')));

        $invitation->markSent($now);
        $invitation->renewKey(str_repeat('b', 64), 'Bcde', $now->modify('+7 days'));
        self::assertSame(EmailStatus::Pending, $invitation->getEmailStatus());
        self::assertNull($invitation->getEmailSentAt());
        self::assertSame(2, $invitation->getSendCount());
        self::assertSame('Bcde', $invitation->getKeyHint());
    }
}
