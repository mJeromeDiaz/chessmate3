<?php

declare(strict_types=1);

namespace App\Training\Plan;

use App\Entity\Training\Plan;
use App\Enum\Training\Module;
use App\Enum\Training\Repetition;
use App\Training\Exception\InvalidSessionException;

/**
 * What a saved session is made of, checked for consistency: a time and days for a repeated
 * session (one day when weekly), known reminder delays and channels, at least one channel for an
 * active reminder.
 */
final readonly class PlanSettings
{
    private const TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';

    /**
     * @param list<array{module: Module, minutes: int, notes: string, settings: array<string, mixed>}> $steps
     * @param list<int>                                                                                 $weekdays
     * @param list<string>                                                                              $reminderChannels
     *
     * @throws InvalidSessionException
     */
    public function __construct(
        public string $title,
        public string $description,
        public array $steps,
        public Repetition $repetition,
        public ?string $time,
        public array $weekdays,
        public bool $public,
        public bool $reminderEnabled,
        public array $reminderChannels,
        public int $reminderMinutes,
        public bool $calendarEnabled,
    ) {
        if (Repetition::OnDemand !== $repetition) {
            if (null === $time || 1 !== preg_match(self::TIME_PATTERN, $time)) {
                throw new InvalidSessionException('time is "HH:MM" for a repeated session.');
            }
            $days = array_values(array_unique($weekdays));
            if ([] === $days || \count($days) !== \count($weekdays) || [] !== array_diff($days, range(1, 7))) {
                throw new InvalidSessionException('weekdays are distinct days, 1 (Monday) to 7 (Sunday).');
            }
            if (Repetition::Weekly === $repetition && 1 !== \count($days)) {
                throw new InvalidSessionException('A weekly session has one day.');
            }
        }
        if (!\in_array($reminderMinutes, Plan::REMINDER_MINUTES, true)) {
            throw new InvalidSessionException(sprintf('reminderMinutes is one of %s.', implode(', ', Plan::REMINDER_MINUTES)));
        }
        if ([] !== array_diff($reminderChannels, Plan::REMINDER_CHANNELS) || \count(array_unique($reminderChannels)) !== \count($reminderChannels)) {
            throw new InvalidSessionException('reminderChannels are email and/or push.');
        }
        if ($reminderEnabled && Repetition::OnDemand !== $repetition && [] === $reminderChannels) {
            throw new InvalidSessionException('A reminder needs a channel.');
        }
    }
}
