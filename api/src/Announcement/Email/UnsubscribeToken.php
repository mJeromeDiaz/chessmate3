<?php

declare(strict_types=1);

namespace App\Announcement\Email;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * The secret of an account's "stop the announcement emails" link (docs/ANNOUNCEMENTS.md, § 5): an
 * HMAC of the account id under the kernel secret, without expiry, so that the link of any email
 * works without signing in. It only ever turns the emails off.
 */
final readonly class UnsubscribeToken
{
    private const PURPOSE = 'announcement-unsubscribe:';

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {
    }

    public function of(Uuid $userId): string
    {
        return hash_hmac('sha256', self::PURPOSE.$userId->toRfc4122(), $this->secret);
    }

    public function isValid(Uuid $userId, string $token): bool
    {
        return hash_equals($this->of($userId), $token);
    }
}
