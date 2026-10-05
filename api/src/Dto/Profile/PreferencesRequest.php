<?php

declare(strict_types=1);

namespace App\Dto\Profile;

use App\Enum\BoardTheme;
use Symfony\Component\Validator\Constraints as Assert;

/** The profile's preferences (design "Profil"): every field is required and replaced. */
final class PreferencesRequest
{
    /** "wood", "glass", "pastel", "tournament" or "slate". */
    #[Assert\NotBlank]
    #[Assert\Choice(callback: [self::class, 'boardThemes'])]
    public string $boardTheme = '';

    #[Assert\NotNull]
    public ?bool $moveSound = null;

    /** Stored only for now: no page shows a profile to other users yet. */
    #[Assert\NotNull]
    public ?bool $publicProfile = null;

    /**
     * @return list<string>
     */
    public static function boardThemes(): array
    {
        return array_map(static fn (BoardTheme $theme): string => $theme->value, BoardTheme::cases());
    }
}
