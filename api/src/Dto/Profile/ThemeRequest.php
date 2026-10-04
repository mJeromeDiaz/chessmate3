<?php

declare(strict_types=1);

namespace App\Dto\Profile;

use App\Enum\Theme;
use Symfony\Component\Validator\Constraints as Assert;

final class ThemeRequest
{
    /** "auto", "light" or "dark". */
    #[Assert\NotBlank]
    #[Assert\Choice(callback: [self::class, 'values'])]
    public string $theme = '';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (Theme $theme): string => $theme->value, Theme::cases());
    }
}
