<?php

declare(strict_types=1);

namespace App\Repertoire\Lichess;

use App\Chess\Rules;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Lichess cloud evaluations (lichess.org/api/cloud-eval: cached engine lines, no token), through
 * {@see LichessGateway}. A position Lichess does not have (404) is remembered for a day, a found
 * one for 7 days. Lines come with their first moves in SAN for display.
 *
 * @phpstan-type CloudLine array{cp: int|null, mate: int|null, moves: list<string>, san: list<string>}
 * @phpstan-type CloudAnswer array{found: bool, depth: int|null, knodes: int|null, lines: list<CloudLine>}
 */
final class CloudEvalClient
{
    public const FOUND_TTL = 7 * 86400;
    public const MISSING_TTL = 86400;
    public const MAX_LINES = 5;
    private const SAN_PLIES = 10;

    public function __construct(
        private readonly HttpClientInterface $lichessApiClient,
        private readonly LichessGateway $gateway,
        #[Autowire(service: 'repertoire.explorer_cache')]
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @param string $fen normalized FEN
     *
     * @return CloudAnswer
     *
     * @throws LichessUnavailableException from the cache callback, which PHPStan does not see called
     *
     * @phpstan-ignore throws.unusedType
     */
    public function evaluate(string $fen, int $lines): array
    {
        $lines = max(1, min(self::MAX_LINES, $lines));

        /** @var CloudAnswer */
        return $this->cache->get('cloud.'.$lines.'.'.hash('xxh128', $fen), function (ItemInterface $item) use ($fen, $lines): array {
            $answer = $this->fetch($fen, $lines);
            $item->expiresAfter($answer['found'] ? self::FOUND_TTL : self::MISSING_TTL);

            return $answer;
        });
    }

    /**
     * @return CloudAnswer
     *
     * @throws LichessUnavailableException
     */
    private function fetch(string $fen, int $lines): array
    {
        $response = $this->gateway->get($this->lichessApiClient, '/api/cloud-eval', ['fen' => $fen.' 0 1', 'multiPv' => $lines], null);
        if (404 === $response['status']) {
            return ['found' => false, 'depth' => null, 'knodes' => null, 'lines' => []];
        }
        if (200 !== $response['status'] || null === $response['body']) {
            throw new LichessUnavailableException(LichessUnavailableException::DOWN);
        }

        return self::normalize($fen, $response['body']);
    }

    /**
     * @param array<mixed> $body
     *
     * @return CloudAnswer
     */
    private static function normalize(string $fen, array $body): array
    {
        $lines = [];
        foreach (\is_array($body['pvs'] ?? null) ? $body['pvs'] : [] as $pv) {
            if (!\is_array($pv) || !\is_string($pv['moves'] ?? null)) {
                continue;
            }
            $moves = array_values(array_filter(explode(' ', $pv['moves'])));
            $rules = Rules::fromFen($fen);
            $san = [];
            foreach (\array_slice($moves, 0, self::SAN_PLIES) as $uci) {
                $move = $rules->playUci($uci);
                if (null === $move) {
                    break;
                }
                $san[] = (string) $move->san;
            }
            $lines[] = [
                'cp' => isset($pv['cp']) && is_numeric($pv['cp']) ? (int) $pv['cp'] : null,
                'mate' => isset($pv['mate']) && is_numeric($pv['mate']) ? (int) $pv['mate'] : null,
                'moves' => $moves,
                'san' => $san,
            ];
        }

        return [
            'found' => true,
            'depth' => isset($body['depth']) && is_numeric($body['depth']) ? (int) $body['depth'] : null,
            'knodes' => isset($body['knodes']) && is_numeric($body['knodes']) ? (int) $body['knodes'] : null,
            'lines' => $lines,
        ];
    }
}
