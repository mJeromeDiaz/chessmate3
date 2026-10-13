<?php

declare(strict_types=1);

namespace App\ApiResource\Coach;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;
use App\ApiResource\Repertoire\Repertoire;
use App\Coach\Diagnosis\Diagnosis as DiagnosisRules;
use App\State\Coach\DiagnosisProvider;

/**
 * What the user's games say (docs/COACH.md), from a build of their Lichess games
 * (`/repertoires/auto-builds`, the games read so far): rating, most played openings by colour,
 * endgames by kind with the advantages let slip, the weakest kind. Another user's build, or an
 * expired one: 404.
 *
 * @phpstan-import-type Opening from DiagnosisRules
 * @phpstan-import-type Endgame from DiagnosisRules
 */
#[ApiResource(
    shortName: 'CoachDiagnosis',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(
            uriTemplate: '/coach/builds/{id}/diagnosis',
            requirements: ['id' => Repertoire::UUID_PATTERN],
            openapi: new Operation(summary: 'The diagnosis of the games of a build (those read so far).'),
            provider: DiagnosisProvider::class,
        ),
    ],
)]
final class Diagnosis
{
    public string $buildId;
    /** fetching, ready, applied: the build's status. */
    public string $status;
    public int $games;
    public int $analysed;
    /** The user's median rating in these games (CoachRules::DEFAULT_RATING without any). */
    public int $rating;
    /** @var list<Opening> */
    public array $white;
    /** @var list<Opening> */
    public array $black;
    /** @var array{games: int, advantages: int, lost: int, types: list<Endgame>} */
    public array $endgames;
    /** @var Endgame|null */
    public ?array $weakest;
}
