<?php

declare(strict_types=1);

namespace App\ApiResource\Repertoire;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\Repertoire\Auto\AutoBuildService;
use App\Repertoire\Auto\Synthesis;
use App\Repertoire\Auto\Tree;
use App\State\Repertoire\AutoBuildActionProcessor;
use App\State\Repertoire\AutoBuildProvider;
use App\State\Repertoire\CreateAutoBuildProcessor;
use App\State\Repertoire\FetchAutoBuildProcessor;

/**
 * A repertoire built from the current user's Lichess games (docs/REPERTOIRE.md, "Repertoire from
 * my games"): created with its settings (linked Lichess account required), filled by POSTing
 * /fetch until it is ready (one batch of games each, the games kept returned for the live feed),
 * then read with the synthesis of what the user plays; then shaped (POST /selection: openings
 * kept, moves chosen, lines removed), its line ends evaluated by the Lichess cloud (POST /evaluate
 * until pendingEvaluations is 0) and applied (POST /apply: one new repertoire per opening).
 * Another user's, or an expired one: 404.
 *
 * @phpstan-import-type FeedItem from AutoBuildService
 * @phpstan-import-type SynthesisArray from Synthesis
 * @phpstan-import-type TreeNode from Tree
 * @phpstan-import-type Line from Tree
 *
 * @phpstan-type LineView array{path: string, cut: string, name: string, nodes: list<string>, games: int, wins: int, draws: int, losses: int, analysed: int, errored: int, fen: string, number: int, tag: string, evaluated: bool, evalCp: int|null, evalMate: int|null, locked: bool}
 *                         number: from 1 across the trees; tag: risk, weapon, draws, solid; evaluated: Lichess was asked (evalCp/evalMate null then: it has none), the user's point of view; locked: beyond the free lines
 * @phpstan-type TreeView array{key: string, family: string, color: string, games: int, wins: int, draws: int, losses: int, trunk: int, nodes: list<TreeNode>, lines: list<LineView>}
 * @phpstan-type SelectionView array{families: list<string>, choices: array<string, array<string, string>>, removed: array<string, list<string>>}
 */
#[ApiResource(
    shortName: 'RepertoireAutoBuild',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Post(
            uriTemplate: '/repertoires/auto-builds',
            openapi: new Operation(summary: 'Starts reading the user\'s Lichess games (replaces their previous build). 409 lichess_not_linked; 422 invalid_settings.'),
            input: CreateAutoBuildInput::class,
            processor: CreateAutoBuildProcessor::class,
        ),
        new Get(
            uriTemplate: '/repertoires/auto-builds/{id}',
            requirements: ['id' => Repertoire::UUID_PATTERN],
            provider: AutoBuildProvider::class,
        ),
        new Post(
            uriTemplate: '/repertoires/auto-builds/{id}/fetch',
            requirements: ['id' => Repertoire::UUID_PATTERN],
            status: 200,
            openapi: new Operation(summary: 'Reads the next batch of games (nothing once ready). 503 when Lichess is unavailable (X-Lichess-Unavailable, Retry-After).'),
            input: false,
            read: false,
            name: 'repertoire_auto_build_fetch',
            processor: FetchAutoBuildProcessor::class,
        ),
        new Post(
            uriTemplate: '/repertoires/auto-builds/{id}/selection',
            requirements: ['id' => Repertoire::UUID_PATTERN],
            status: 200,
            openapi: new Operation(summary: 'Replaces the selection: openings kept, moves chosen (family => node path => UCI), lines removed (family => node paths). 409 not_ready, already_applied; 422 invalid_selection.'),
            input: AutoBuildSelectionInput::class,
            read: false,
            name: 'repertoire_auto_build_select',
            processor: AutoBuildActionProcessor::class,
        ),
        new Post(
            uriTemplate: '/repertoires/auto-builds/{id}/evaluate',
            requirements: ['id' => Repertoire::UUID_PATTERN],
            status: 200,
            openapi: new Operation(summary: 'Asks the Lichess cloud about a few more line ends. 503 when Lichess is unavailable and nothing was learnt.'),
            input: false,
            read: false,
            name: 'repertoire_auto_build_evaluate',
            processor: AutoBuildActionProcessor::class,
        ),
        new Post(
            uriTemplate: '/repertoires/auto-builds/{id}/apply',
            requirements: ['id' => Repertoire::UUID_PATTERN],
            status: 200,
            openapi: new Operation(summary: 'Creates one repertoire per opening kept (repertoireIds). 409 not_ready, already_applied; 422 nothing_selected, limit_repertoires.'),
            input: false,
            read: false,
            name: 'repertoire_auto_build_apply',
            processor: AutoBuildActionProcessor::class,
        ),
    ],
)]
final class AutoBuild
{
    #[ApiProperty(identifier: true)]
    public string $id;
    /** fetching, ready, applied */
    public string $status;
    /** The Lichess account read. */
    public string $username;
    /** Games wanted. */
    public int $maxGames;
    /** @var list<string> Lichess speeds read */
    public array $perfs = [];
    /** Games kept so far. */
    public int $games;
    /** Games Lichess sent so far, kept or not. */
    public int $scanned;
    /** Why reading stopped early (lichess_user_not_found). */
    public ?string $error = null;
    /** @var list<FeedItem>|null the games kept by this request (POST /fetch only), newest first */
    public ?array $batch = null;
    /** @var SynthesisArray|null once ready */
    public ?array $synthesis = null;
    /** @var SelectionView|null what the user chose (null: nothing yet) */
    public ?array $selection = null;
    /** @var list<TreeView> the trees of the openings kept */
    public array $trees = [];
    /** Line ends Lichess was not asked about yet (POST /evaluate). */
    public int $pendingEvaluations = 0;
    /** Lines shown in full without a subscription, null: all of them. */
    public ?int $freeLines = null;
    /** @var list<string> the repertoires created, once applied */
    public array $repertoireIds = [];
    public \DateTimeImmutable $expiresAt;
}
