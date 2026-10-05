<?php

declare(strict_types=1);

namespace App\Security\Profile;

use App\Entity\User;
use App\Repository\UserRepository;

/**
 * Rules of the public username ("@lea_echecs"): 3 to 20 of [a-z0-9_], not reserved, not used by
 * another account. The unique index on app_user.handle still settles two simultaneous claims.
 */
final readonly class HandleChecker
{
    public const string INVALID = 'invalid';
    public const string RESERVED = 'reserved';
    public const string TAKEN = 'taken';

    private const string PATTERN = '/^[a-z0-9_]{3,20}$/';

    /** Names that could pass for the app or its staff. */
    private const array RESERVED_HANDLES = [
        'admin', 'administrator', 'api', 'chessmate', 'contact', 'help', 'lichess', 'mod',
        'moderator', 'null', 'root', 'staff', 'support', 'system', 'undefined',
    ];

    public function __construct(private UserRepository $users)
    {
    }

    /** Lower case, surrounding spaces removed: "Lea_Echecs " and "lea_echecs" are the same. */
    public function normalize(string $handle): string
    {
        return strtolower(trim($handle));
    }

    /**
     * Why `$user` cannot take this (normalized) handle, or null if it can: {@see self::INVALID},
     * {@see self::RESERVED} or {@see self::TAKEN}. The user's own current handle is available.
     */
    public function refusal(string $handle, User $user): ?string
    {
        if (1 !== preg_match(self::PATTERN, $handle)) {
            return self::INVALID;
        }
        if (\in_array($handle, self::RESERVED_HANDLES, true)) {
            return self::RESERVED;
        }
        $owner = $this->users->findOneBy(['handle' => $handle]);

        return null !== $owner && !$owner->getId()->equals($user->getId()) ? self::TAKEN : null;
    }
}
