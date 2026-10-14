<?php

declare(strict_types=1);

namespace App\Dto\Profile;

use App\Enum\Locale;
use Symfony\Component\Validator\Constraints as Assert;

final class LocaleRequest
{
    /** "fr" or "en". */
    #[Assert\NotBlank]
    #[Assert\Choice(callback: [self::class, 'values'])]
    public string $locale = '';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (Locale $locale): string => $locale->value, Locale::cases());
    }
}
