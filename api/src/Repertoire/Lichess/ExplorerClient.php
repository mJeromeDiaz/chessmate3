<?php

declare(strict_types=1);

namespace App\Repertoire\Lichess;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The Lichess opening explorer (explorer.lichess.org/masters and /lichess), through the proxy rules
 * of {@see LichessGateway}. Answers are cached in MySQL for everyone (public statistics): 30 days
 * for the masters database (updated rarely), 7 days for the Lichess one. A token is required: the
 * user's when their Lichess account is linked, else the application's; a 401 with the first one
 * falls back to the next.
 *
 * @phpstan-type ExplorerMove array{uci: string, san: string, white: int, draws: int, black: int, total: int, averageRating: int|null}
 * @phpstan-type ExplorerAnswer array{white: int, draws: int, black: int, total: int, moves: list<ExplorerMove>, opening: array{eco: string, name: string}|null}
 */
final class ExplorerClient
{
    public const MASTERS_TTL = 30 * 86400;
    public const LICHESS_TTL = 7 * 86400;

    public function __construct(
        private readonly HttpClientInterface $lichessExplorerClient,
        private readonly LichessGateway $gateway,
        private readonly TokenResolver $tokens,
        #[Autowire(service: 'repertoire.explorer_cache')]
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @return ExplorerAnswer
     *
     * @throws LichessUnavailableException
     */
    public function explore(User $user, ExplorerQuery $query): array
    {
        /** @var ExplorerAnswer */
        return $this->cache->get($query->cacheKey(), function (ItemInterface $item) use ($user, $query): array {
            $item->expiresAfter(ExplorerQuery::MASTERS === $query->source ? self::MASTERS_TTL : self::LICHESS_TTL);

            return self::normalize($this->fetch($user, $query));
        });
    }

    /**
     * @return array<mixed>
     */
    private function fetch(User $user, ExplorerQuery $query): array
    {
        $tokens = $this->tokens->candidates($user);
        if ([] === $tokens) {
            throw new LichessUnavailableException(LichessUnavailableException::NO_TOKEN);
        }
        foreach ($tokens as $token) {
            $response = $this->gateway->get($this->lichessExplorerClient, '/'.$query->source, $query->toQuery(), $token);
            if (401 === $response['status'] || 403 === $response['status']) {
                continue;
            }
            if (200 !== $response['status'] || null === $response['body']) {
                throw new LichessUnavailableException(LichessUnavailableException::DOWN);
            }

            return $response['body'];
        }

        throw new LichessUnavailableException(LichessUnavailableException::NO_TOKEN);
    }

    /**
     * Keeps what the panel shows, typed (the answer is cached as such).
     *
     * @param array<mixed> $body
     *
     * @return ExplorerAnswer
     */
    private static function normalize(array $body): array
    {
        $moves = [];
        foreach (\is_array($body['moves'] ?? null) ? $body['moves'] : [] as $move) {
            if (!\is_array($move) || !\is_string($move['uci'] ?? null) || !\is_string($move['san'] ?? null)) {
                continue;
            }
            $white = self::int($move['white'] ?? 0);
            $draws = self::int($move['draws'] ?? 0);
            $black = self::int($move['black'] ?? 0);
            $moves[] = [
                'uci' => $move['uci'],
                'san' => $move['san'],
                'white' => $white,
                'draws' => $draws,
                'black' => $black,
                'total' => $white + $draws + $black,
                'averageRating' => isset($move['averageRating']) ? self::int($move['averageRating']) : null,
            ];
        }
        $opening = \is_array($body['opening'] ?? null) && \is_string($body['opening']['eco'] ?? null) && \is_string($body['opening']['name'] ?? null)
            ? ['eco' => $body['opening']['eco'], 'name' => $body['opening']['name']]
            : null;
        $white = self::int($body['white'] ?? 0);
        $draws = self::int($body['draws'] ?? 0);
        $black = self::int($body['black'] ?? 0);

        return ['white' => $white, 'draws' => $draws, 'black' => $black, 'total' => $white + $draws + $black, 'moves' => $moves, 'opening' => $opening];
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }
}
