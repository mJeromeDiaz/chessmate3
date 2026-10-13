<?php

declare(strict_types=1);

namespace App\Repertoire\Auto;

use App\Repertoire\Lichess\LichessGateway;
use App\Repertoire\Lichess\LichessUnavailableException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A batch of a user's Lichess games (GET /api/games/user/{username}, ndjson, newest first),
 * through {@see LichessGateway} like every call to Lichess: finished games of the speeds asked,
 * with their moves, clocks, computer analysis and opening name.
 *
 * Small batches keep the application-wide Lichess lock short: Lichess streams 60 games a second
 * of one's own games with one's token (20 anonymously), the user's token is sent and dropped after
 * a 401 (revoked).
 */
final readonly class GamesClient
{
    public function __construct(
        private HttpClientInterface $lichessApiClient,
        private LichessGateway $gateway,
    ) {
    }

    /**
     * @param list<string> $perfs   bullet, blitz, rapid, classical
     * @param int|null     $untilMs games created before this instant only
     *
     * @return list<array<mixed>>|null the games (decoded), null when Lichess has no such user
     *
     * @throws LichessUnavailableException
     */
    public function batch(string $username, #[\SensitiveParameter] ?string $token, int $max, ?int $untilMs, array $perfs): ?array
    {
        $path = '/api/games/user/'.rawurlencode($username);
        $query = [
            'max' => $max,
            'perfType' => implode(',', $perfs),
            'finished' => 'true',
            'moves' => 'true',
            'clocks' => 'true',
            'evals' => 'true',
            'opening' => 'true',
            'pgnInJson' => 'false',
        ];
        if (null !== $untilMs) {
            $query['until'] = $untilMs;
        }
        $headers = ['Accept' => 'application/x-ndjson'];
        $response = $this->gateway->get($this->lichessApiClient, $path, $query, $token, $headers);
        if (401 === $response['status'] && null !== $token) {
            $response = $this->gateway->get($this->lichessApiClient, $path, $query, null, $headers);
        }
        if (404 === $response['status']) {
            return null;
        }
        if (200 !== $response['status'] || null === $response['text']) {
            throw new LichessUnavailableException(LichessUnavailableException::DOWN);
        }

        $games = [];
        foreach (explode("\n", $response['text']) as $line) {
            if ('' === trim($line)) {
                continue;
            }
            $game = json_decode($line, true);
            if (\is_array($game)) {
                $games[] = $game;
            }
        }

        return $games;
    }
}
