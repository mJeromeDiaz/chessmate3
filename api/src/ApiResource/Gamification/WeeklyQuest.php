<?php

declare(strict_types=1);

namespace App\ApiResource\Gamification;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Gamification\Quest\QuestService;
use App\State\Gamification\SummaryProvider;

/**
 * The signed-in user's quest of the week (docs/GAMIFICATION.md): drawn on its first read, its
 * progress, completed once with its reward gained as XP.
 *
 * @phpstan-import-type QuestView from QuestService
 */
#[ApiResource(
    shortName: 'GamificationQuest',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(uriTemplate: '/gamification/quest', provider: SummaryProvider::class),
    ],
)]
final class WeeklyQuest
{
    public string $id = '';
    /** rated_puzzles, weak_theme, sessions, woodpecker, repertoire or active_days. */
    public string $template = '';
    /** The theme of a weak_theme quest (Lichess key). */
    public ?string $theme = null;
    /** Its module (its professor), null for a quest across modules. */
    public ?string $module = null;
    public int $goal = 0;
    public int $current = 0;
    /** XP gained once completed. */
    public int $reward = 0;
    public bool $completed = false;
    public ?string $completedAt = null;
    /** Monday and Sunday of the local week (Y-m-d). */
    public string $weekStart = '';
    public string $weekEnd = '';
}
