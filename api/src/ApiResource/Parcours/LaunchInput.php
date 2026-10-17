<?php

declare(strict_types=1);

namespace App\ApiResource\Parcours;

use App\Enum\Parcours\Effort;
use App\Enum\Parcours\Theme;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /parcours (docs/PARCOURS.md): a theme, an effort, and the repertoire of an opening parcours.
 */
final class LaunchInput
{
    #[Assert\NotBlank]
    #[Assert\Choice(callback: [Theme::class, 'values'])]
    public string $theme = '';

    #[Assert\NotBlank]
    #[Assert\Choice(callback: [Effort::class, 'values'])]
    public string $effort = '';

    #[Assert\Uuid]
    public ?string $repertoireId = null;
}
