<?php

declare(strict_types=1);

namespace App\Dto\Profile;

use App\Enum\Avatar;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the profile shows of the user (design "Profil", "Modifier le profil"). Each field replaces
 * the stored one; empty or null clears it. The handle's own rules are checked by
 * {@see \App\Security\Profile\HandleChecker}.
 */
final class InfoRequest
{
    #[Assert\Length(max: 40)]
    #[Assert\Regex(pattern: '/^[^\p{C}]*$/u', message: 'The display name cannot contain control characters.')]
    public ?string $displayName = null;

    #[Assert\Length(max: 40)]
    public ?string $handle = null;

    /** "knight", "bishop", "queen", "rook", "king", "pawn" or null. */
    #[Assert\Choice(callback: [self::class, 'avatars'])]
    public ?string $avatar = null;

    /**
     * @return list<string>
     */
    public static function avatars(): array
    {
        return array_map(static fn (Avatar $avatar): string => $avatar->value, Avatar::cases());
    }
}
