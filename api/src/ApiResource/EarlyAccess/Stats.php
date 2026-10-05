<?php

declare(strict_types=1);

namespace App\ApiResource\EarlyAccess;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\EarlyAccess\Stats\CommunityActivity;
use App\EarlyAccess\Stats\InvitationFunnel;
use App\EarlyAccess\Stats\RatingDistribution;
use App\EarlyAccess\Stats\Signups;
use App\State\EarlyAccess\StatsProvider;

/**
 * The admin dashboard in one answer (docs/EARLY_ACCESS.md), over the last `days` local days of the
 * admin: invitations, sign-ups, community activity, and the players' Lichess ratings (today).
 *
 * @phpstan-import-type Funnel from InvitationFunnel
 * @phpstan-import-type Summary from Signups as SignupSummary
 * @phpstan-import-type Summary from CommunityActivity as ActivitySummary
 * @phpstan-import-type Distribution from RatingDistribution
 */
#[ApiResource(
    shortName: 'EarlyAccessStats',
    security: "is_granted('ROLE_ADMIN')",
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(
            uriTemplate: '/admin/stats',
            openapi: new Operation(
                summary: 'Invitations, sign-ups, activity and ratings for the admin dashboard.',
                parameters: [
                    new Parameter('days', 'query', 'Number of local days, today included (7 to 371, default 30)', schema: ['type' => 'integer']),
                ],
            ),
            provider: StatsProvider::class,
        ),
    ],
)]
final class Stats
{
    public const DEFAULT_DAYS = 30;

    /** The admin's timezone, in which the days are counted. */
    public string $timezone;
    /** First day of the period (Y-m-d, local). */
    public string $from;
    /** Today (Y-m-d, local). */
    public string $today;
    /** @var Funnel invitations created during the period */
    public array $invitations;
    /** @var SignupSummary */
    public array $signups;
    /** @var ActivitySummary */
    public array $activity;
    /** @var Distribution */
    public array $ratings;
}
