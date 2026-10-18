<?php

declare(strict_types=1);

namespace App\Repertoire;

/**
 * Server-side limits of the repertoire domain (docs/REPERTOIRE.md). One place, so that freemium
 * limits (later) can replace these values per user.
 */
final readonly class Limits
{
    /**
     * Repertoire from my games: the Lichess explorer filters the opponents' replies are read with
     * (named, so that others can be added; one only for now, which repertoire_opening_stats holds).
     */
    public const EXPLORER_PRESETS = [
        'strong' => ['speeds' => ['blitz', 'rapid', 'classical'], 'ratings' => [2000, 2200, 2500]],
    ];
    public const AUTO_EXPLORER_PRESET = 'strong';

    public function __construct(
        public int $maxRepertoires = 50,
        /** Positions per repertoire, the initial one included. */
        public int $maxPositions = 5000,
        /** Plies from the initial position. */
        public int $maxDepth = 80,
        /** Changes that can be undone. */
        public int $undoDepth = 50,
        /** PGN text of an import (file, paste or study). */
        public int $maxImportBytes = 1_048_576,
        /** Games or chapters of an import. */
        public int $maxImportGames = 300,
        /** Imports up to this size are analysed during the request, bigger ones by a worker. */
        public int $syncImportBytes = 102_400,
        /** Imports adding up to this many positions are applied during the request, others by a worker. */
        public int $syncImportPositions = 500,
        /** An import not applied is forgotten after this many hours. */
        public int $importTtlHours = 24,
        /** Repertoire from my games: an opponent's reply kept (and the line followed) if played this often. */
        public int $autoMinGames = 2,
        /** Repertoire from my games: an opening family offered if played this often. */
        public int $autoFamilyMinGames = 3,
        /** Repertoire from my games: plies read in each game, and depth of the lines. */
        public int $autoMaxPlies = 40,
        /** Repertoire from my games: the user's other moves offered instead of the most played one when played this often. */
        public int $autoAlternativeMinGames = 2,
        /** Repertoire from my games: games asked of Lichess per request (keeps its application-wide lock short). */
        public int $autoBatchGames = 50,
        /** Repertoire from my games: games a build may read, and the choices offered. */
        public int $autoMaxGames = 2000,
        /** Repertoire from my games: a build not applied is forgotten after this many hours. */
        public int $autoTtlHours = 24,
        /** Repertoire from my games: lines shown in full without a subscription; null = no lock (no subscription exists yet). */
        public ?int $autoFreeLines = null,
        /** Repertoire from my games: line ends evaluated by the Lichess cloud per request (each one may call Lichess). */
        public int $autoEvaluationsPerRequest = 5,
        /** Line tags: "at risk" below this score (%) or at this evaluation or worse (centipawns, the user's point of view)... */
        public int $autoRiskScore = 40,
        public int $autoRiskEval = -100,
        /** ...a "weapon" from this score (%) with an evaluation not below 0, "draws" from this share of draws (%). */
        public int $autoWeaponScore = 60,
        public int $autoDrawShare = 30,
        /** Repertoire from my games: an opponent's reply from the explorer kept from this share of the position's games (%)... */
        public float $autoReplyMinShare = 5.0,
        /** ...and this many games; at most this many replies per position. */
        public int $autoReplyMinGames = 100,
        public int $autoMaxReplies = 4,
        /** Repertoire from my games: real explorer calls of an analysis (or of a resume, or of "explore further" on a line); 500 and 1 000 are kept for deeper settings. */
        public int $autoMaxExplorerCalls = 300,
        /** Repertoire from my games: explorer calls per request (the SPA asks again until the analysis is explored). */
        public int $autoExplorerCallsPerRequest = 10,
        /** Repertoire from my games: a line shorter than this (plies) is kept only once the user confirmed it. */
        public int $autoShortLinePlies = 20,
        /** Precomputed first moves (repertoire_opening_stats): plies from the initial position, and days before a row is refreshed. */
        public int $openingStatsPlies = 4,
        public int $openingStatsTtlDays = 30,
    ) {
    }
}
