<?php

declare(strict_types=1);

namespace App\Dashboard\Lichess;

use App\Repertoire\Lichess\LichessGateway;
use App\Repertoire\Lichess\LichessUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A Lichess player's game rating history (lichess.org/api/user/{id}/rating-history), blitz, rapid
 * and classical only, through {@see LichessGateway} like every call to Lichess. Cached 6 hours per
 * player in the shared MySQL pool: the dashboard never asks Lichess twice in a row.
 *
 * Lichess answers an anonymous request from its own cache, or with an empty list when it has
 * none: the player's own token (linked account) is sent when there is one, and dropped after a
 * 401 (revoked token). An unknown or closed account (404) has no history.
 *
 * @phpstan-type Point array{date: string, rating: int}
 * @phpstan-type Perfs array{blitz: list<Point>, rapid: list<Point>, classical: list<Point>}
 */
final class RatingHistoryClient
{
    public const TTL = 6 * 3600;
    /** Lichess perf name => ours. */
    public const PERFS = ['Blitz' => 'blitz', 'Rapid' => 'rapid', 'Classical' => 'classical'];
    /** Lichess user ids: letters, digits, _ and -, at most 30 characters. */
    public const ID_PATTERN = '/^[A-Za-z0-9_-]{1,30}$/';

    public function __construct(
        private readonly HttpClientInterface $lichessApiClient,
        private readonly LichessGateway $gateway,
        #[Autowire(service: 'repertoire.explorer_cache')]
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @return Perfs every point Lichess knows, oldest first
     *
     * @throws LichessUnavailableException from the cache callback
     */
    public function history(string $lichessId, #[\SensitiveParameter] ?string $token): array
    {
        $id = strtolower($lichessId);
        if (1 !== preg_match(self::ID_PATTERN, $id)) {
            return self::empty();
        }

        /** @var Perfs */
        return $this->cache->get('lichess.rating_history.'.$id, function (ItemInterface $item) use ($id, $token): array {
            $item->expiresAfter(self::TTL);

            return $this->fetch($id, $token);
        });
    }

    /**
     * @return Perfs
     *
     * @throws LichessUnavailableException
     */
    private function fetch(string $id, #[\SensitiveParameter] ?string $token): array
    {
        $path = '/api/user/'.$id.'/rating-history';
        $response = $this->gateway->get($this->lichessApiClient, $path, [], $token);
        if (401 === $response['status'] && null !== $token) {
            $response = $this->gateway->get($this->lichessApiClient, $path, [], null);
        }
        if (404 === $response['status']) {
            return self::empty();
        }
        if (200 !== $response['status'] || null === $response['body']) {
            throw new LichessUnavailableException(LichessUnavailableException::DOWN);
        }

        return self::normalize($response['body']);
    }

    /**
     * @param array<mixed> $body [{name: "Blitz", points: [[year, month (0-11), day, rating], ...]}, ...]
     *
     * @return Perfs
     */
    public static function normalize(array $body): array
    {
        $perfs = self::empty();
        foreach ($body as $perf) {
            if (!\is_array($perf) || !\is_string($perf['name'] ?? null) || !isset(self::PERFS[$perf['name']]) || !\is_array($perf['points'] ?? null)) {
                continue;
            }
            $byDay = [];
            foreach ($perf['points'] as $point) {
                if (!\is_array($point) || 4 !== \count($point) || !array_is_list($point)) {
                    continue;
                }
                [$year, $month, $day, $rating] = $point;
                if (!\is_int($year) || !\is_int($month) || !\is_int($day) || !\is_int($rating) || !checkdate($month + 1, $day, $year)) {
                    continue;
                }
                $byDay[\sprintf('%04d-%02d-%02d', $year, $month + 1, $day)] = $rating;
            }
            ksort($byDay);
            $points = [];
            foreach ($byDay as $date => $rating) {
                $points[] = ['date' => (string) $date, 'rating' => $rating];
            }
            $perfs[self::PERFS[$perf['name']]] = $points;
        }

        return $perfs;
    }

    /**
     * The points of a period, opened on its first day by the last rating known before it.
     *
     * @param list<Point> $points oldest first
     *
     * @return list<Point>
     */
    public static function window(array $points, string $from, string $today): array
    {
        $kept = [];
        $before = null;
        foreach ($points as $point) {
            if ($point['date'] < $from) {
                $before = $point['rating'];
            } elseif ($point['date'] <= $today) {
                $kept[] = $point;
            }
        }
        if (null !== $before && ([] === $kept || $kept[0]['date'] !== $from)) {
            array_unshift($kept, ['date' => $from, 'rating' => $before]);
        }

        return $kept;
    }

    /**
     * @return Perfs
     */
    private static function empty(): array
    {
        return ['blitz' => [], 'rapid' => [], 'classical' => []];
    }
}
