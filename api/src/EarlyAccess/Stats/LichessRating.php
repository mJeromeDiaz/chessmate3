<?php

declare(strict_types=1);

namespace App\EarlyAccess\Stats;

/**
 * The "Elo" of a player on the admin dashboard (docs/EARLY_ACCESS.md): the Lichess rating
 * snapshot kept in the linked identity's metadata (refreshed at each Lichess sign-in), rapid first,
 * else blitz, else classical; a provisional rating does not count.
 */
final class LichessRating
{
    /** In order of preference. */
    public const PERFS = ['rapid', 'blitz', 'classical'];

    /**
     * @param array<mixed> $metadata the identity's metadata (`ratings` as written by the Lichess client)
     *
     * @return array{perf: string, rating: int}|null
     */
    public static function pick(array $metadata): ?array
    {
        $ratings = $metadata['ratings'] ?? null;
        if (!\is_array($ratings)) {
            return null;
        }
        foreach (self::PERFS as $perf) {
            $entry = $ratings[$perf] ?? null;
            if (\is_array($entry) && \is_int($entry['rating'] ?? null) && true !== ($entry['provisional'] ?? false)) {
                return ['perf' => $perf, 'rating' => $entry['rating']];
            }
        }

        return null;
    }
}
