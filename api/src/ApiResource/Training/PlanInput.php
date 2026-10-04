<?php

declare(strict_types=1);

namespace App\ApiResource\Training;

use App\Entity\Training\Session;
use App\Enum\Training\Repetition;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A saved session to create or change: its program and its settings (consistency checked by
 * {@see \App\Training\Plan\PlanSettings}, steps by their modules).
 */
final class PlanInput
{
    #[Assert\Length(max: 120)]
    public string $title = '';

    #[Assert\Length(max: 500)]
    public string $description = '';

    /** @var list<SessionStepInput> */
    #[Assert\Count(min: 1, max: Session::MAX_STEPS)]
    #[Assert\Valid]
    public array $steps = [];

    #[Assert\Choice(callback: [Repetition::class, 'values'])]
    public string $repetition = 'on_demand';

    /** Local time "HH:MM" for a repeated session. */
    public ?string $time = null;

    /** @var list<int> ISO days of the week, 1 = Monday */
    #[Assert\Count(max: 7)]
    #[Assert\All([new Assert\Type('integer')])]
    public array $weekdays = [];

    public bool $public = false;

    public bool $reminderEnabled = false;

    /** @var list<string> */
    #[Assert\Count(max: 2)]
    #[Assert\All([new Assert\Type('string')])]
    public array $reminderChannels = [];

    public int $reminderMinutes = 30;

    public bool $calendarEnabled = false;
}
