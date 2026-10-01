<?php

declare(strict_types=1);

namespace App\Repertoire\Lichess;

/**
 * A question to the Lichess opening explorer: a position (normalized FEN), a source and, for the
 * Lichess games database, speed and rating filters (values as the explorer knows them).
 */
final readonly class ExplorerQuery
{
    public const MASTERS = 'masters';
    public const LICHESS = 'lichess';
    public const SPEEDS = ['ultraBullet', 'bullet', 'blitz', 'rapid', 'classical', 'correspondence'];
    public const RATINGS = [0, 1000, 1200, 1400, 1600, 1800, 2000, 2200, 2500];

    /** @var list<string> */
    public array $speeds;
    /** @var list<int> */
    public array $ratings;

    /**
     * @param list<string> $speeds  subset of {@see self::SPEEDS} (lichess only), sorted here
     * @param list<int>    $ratings subset of {@see self::RATINGS} (lichess only), sorted here
     *
     * @throws \InvalidArgumentException unknown source or filter value
     */
    public function __construct(
        public string $source,
        public string $fen,
        array $speeds = [],
        array $ratings = [],
    ) {
        if (!\in_array($source, [self::MASTERS, self::LICHESS], true)) {
            throw new \InvalidArgumentException('Unknown explorer source.');
        }
        if ([] !== array_diff($speeds, self::SPEEDS) || [] !== array_diff($ratings, self::RATINGS)) {
            throw new \InvalidArgumentException('Unknown speed or rating group.');
        }
        $speeds = self::LICHESS === $source ? array_values(array_unique($speeds)) : [];
        $ratings = self::LICHESS === $source ? array_values(array_unique($ratings)) : [];
        sort($speeds);
        sort($ratings);
        $this->speeds = $speeds;
        $this->ratings = $ratings;
    }

    /** Cache key: same position and filters, same answer, whoever asks. */
    public function cacheKey(): string
    {
        return 'explorer.'.$this->source.'.'.hash('xxh128', implode('|', [$this->fen, implode(',', $this->speeds), implode(',', $this->ratings)]));
    }

    /**
     * @return array<string, scalar>
     */
    public function toQuery(): array
    {
        $query = ['fen' => $this->fen.' 0 1', 'moves' => 12, 'topGames' => 0];
        if (self::LICHESS === $this->source) {
            $query['recentGames'] = 0;
            if ([] !== $this->speeds) {
                $query['speeds'] = implode(',', $this->speeds);
            }
            if ([] !== $this->ratings) {
                $query['ratings'] = implode(',', $this->ratings);
            }
        }

        return $query;
    }
}
