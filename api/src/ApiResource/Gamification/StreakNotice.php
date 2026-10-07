<?php

declare(strict_types=1);

namespace App\ApiResource\Gamification;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\State\Gamification\StreakNoticeProcessor;
use App\State\Gamification\StreakNoticeProvider;

/**
 * The signed-in user's streak announcement of today, not shown yet (docs/GAMIFICATION.md, "Annonce
 * de la série"): pending false when there is none.
 */
#[ApiResource(
    shortName: 'GamificationStreakNotice',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(uriTemplate: '/gamification/streak/notice', provider: StreakNoticeProvider::class),
        new Post(
            uriTemplate: '/gamification/streak/notice/acknowledgement',
            status: 204,
            openapi: new Operation(summary: 'Marks today\'s announcement as shown (idempotent).'),
            input: false,
            output: false,
            read: false,
            processor: StreakNoticeProcessor::class,
        ),
    ],
)]
final class StreakNotice
{
    public bool $pending = false;
    /** The streak today reached. */
    public ?int $streak = null;
    /** The streak before today (0: it had broken). */
    public ?int $previousStreak = null;
    /** The streak badge (trophy key) today reached. */
    public ?string $badge = null;
    /** @var list<bool>|null the active days of the current local week, Monday first, today included */
    public ?array $week = null;
    /** The next streak badge's length, null past the last one. */
    public ?int $nextMilestone = null;
    /** The user's local day (Y-m-d). */
    public ?string $localDate = null;
}
