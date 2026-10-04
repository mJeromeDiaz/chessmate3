<?php

declare(strict_types=1);

namespace App\ApiResource\Training;

use App\Enum\Training\Module;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One step of a session to launch: a module, its length and its settings (checked by the module).
 */
final class SessionStepInput
{
    #[Assert\NotBlank]
    #[Assert\Choice(callback: [Module::class, 'values'])]
    public string $module = '';

    #[Assert\Range(min: 1, max: 60)]
    public int $minutes = 20;

    #[Assert\Length(max: 500)]
    public string $notes = '';

    /** @var array<string, mixed> module settings (docs/TRAINING.md, sessions) */
    #[Assert\Count(max: 10)]
    public array $settings = [];
}
