<?php

declare(strict_types=1);

namespace App\ApiResource\Training;

use App\Enum\Training\Module;
use App\Training\Run\TimeboxRunner;
use Symfony\Component\Validator\Constraints as Assert;

final class StartRunInput
{
    #[Assert\NotBlank]
    #[Assert\Choice(callback: [Module::class, 'values'])]
    public string $module = '';

    /** The module's subject (e.g. a Woodpecker set id). */
    #[Assert\NotBlank]
    #[Assert\Uuid]
    public string $subjectId = '';

    #[Assert\Range(min: TimeboxRunner::MIN_BUDGET_SECONDS, max: TimeboxRunner::MAX_BUDGET_SECONDS)]
    public int $budgetSeconds = 1200;

    /** @var array<string, mixed> module-specific options (none today) */
    #[Assert\Count(max: 10)]
    public array $config = [];
}
