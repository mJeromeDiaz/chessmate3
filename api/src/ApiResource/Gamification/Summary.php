<?php

declare(strict_types=1);

namespace App\ApiResource\Gamification;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Gamification\Summary\SummaryReader;
use App\State\Gamification\SummaryProvider;

/**
 * The signed-in user's gamification (docs/GAMIFICATION.md): XP, level, rank, module levels,
 * streaks, today's exercise XP against the daily cap.
 *
 * @phpstan-import-type ModuleLevel from SummaryReader
 */
#[ApiResource(
    shortName: 'GamificationSummary',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(uriTemplate: '/gamification/summary', provider: SummaryProvider::class),
    ],
)]
final class Summary
{
    public int $xp = 0;
    public int $level = 1;
    /** XP gained in the current level. */
    public int $xpInLevel = 0;
    /** XP the current level needs to reach the next one. */
    public int $xpForNext = 0;
    public string $rank = '';
    /** @var array{rank: string, level: int}|null */
    public ?array $nextRank = null;
    /** @var array<string, ModuleLevel> by module (Module values) */
    public array $modules = [];
    /** @var array{current: int, best: int, playedToday: bool} */
    public array $streak = ['current' => 0, 'best' => 0, 'playedToday' => false];
    /** @var array{exerciseXp: int, cap: int} */
    public array $today = ['exerciseXp' => 0, 'cap' => 0];
}
