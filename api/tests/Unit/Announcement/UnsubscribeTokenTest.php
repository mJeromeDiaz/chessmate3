<?php

declare(strict_types=1);

namespace App\Tests\Unit\Announcement;

use App\Announcement\Email\UnsubscribeToken;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The "stop the announcement emails" link's token (docs/ANNOUNCEMENTS.md, § 5).
 */
final class UnsubscribeTokenTest extends TestCase
{
    public function testTheTokenBelongsToOneAccountAndOneSecret(): void
    {
        $alice = Uuid::fromString('0192f6c4-0000-7000-8000-000000000001');
        $bob = Uuid::fromString('0192f6c4-0000-7000-8000-000000000002');
        $tokens = new UnsubscribeToken('secret');

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $tokens->of($alice));
        self::assertTrue($tokens->isValid($alice, $tokens->of($alice)));
        self::assertFalse($tokens->isValid($bob, $tokens->of($alice)));
        self::assertFalse((new UnsubscribeToken('other'))->isValid($alice, $tokens->of($alice)));
        self::assertFalse($tokens->isValid($alice, ''));
    }
}
