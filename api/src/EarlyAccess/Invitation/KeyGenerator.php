<?php

declare(strict_types=1);

namespace App\EarlyAccess\Invitation;

use App\Entity\EarlyAccess\InvitationKey;

/**
 * Invitation keys: 32 characters drawn uniformly from [A-Za-z0-9] with the CSPRNG (~190 bits, out
 * of reach of any guessing), stored as their sha256 only.
 */
final class KeyGenerator
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    public function generate(): string
    {
        $key = '';
        $last = \strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < InvitationKey::KEY_LENGTH; ++$i) {
            $key .= self::ALPHABET[random_int(0, $last)];
        }

        return $key;
    }

    public static function hash(#[\SensitiveParameter] string $key): string
    {
        return hash('sha256', $key);
    }

    public static function hint(#[\SensitiveParameter] string $key): string
    {
        return substr($key, 0, InvitationKey::HINT_LENGTH);
    }

    /** Has the shape of a key (checked before any lookup). */
    public static function isWellFormed(#[\SensitiveParameter] string $key): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9]{'.InvitationKey::KEY_LENGTH.'}$/D', $key);
    }
}
