<?php

declare(strict_types=1);

namespace App\MindMap\Path;

use App\Enum\MindMap\StepKind;

/**
 * A step of a path (docs/MIND_MAP.md, § 1): an exercise, a chest, a boss. $key is stable (stored
 * with the progress, URIs), $number what the path shows (« 2.1.3 »). Its texts are messages of the
 * front (mindMap.<capacity>.steps.<key>).
 */
final readonly class Step
{
    /**
     * @param list<string> $badges    earned with the step
     * @param string|null  $rewardKey its crystals are paid once per key on the path, whatever the
     *                                step (the défis' opening bosses: an opening and a side)
     */
    public function __construct(
        public string $key,
        public ?string $number,
        public StepKind $kind,
        public int $passes,
        public int $crystals,
        public int $xp,
        public ?Activity $activity = null,
        public ?string $character = null,
        public array $badges = [],
        public ?string $rewardKey = null,
    ) {
    }

    /** Whether it can be played today (its module exists). A chest is never played. */
    public function isPlayable(): bool
    {
        return null !== $this->activity;
    }
}
