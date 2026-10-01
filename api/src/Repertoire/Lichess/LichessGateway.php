<?php

declare(strict_types=1);

namespace App\Repertoire\Lichess;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Every call to Lichess from the repertoire goes through here (docs/REPERTOIRE.md), following
 * Lichess' rules: "only make one request at a time", and after a 429 wait (at least a minute)
 * before asking again.
 *
 * - One request at a time for the whole application: MySQL GET_LOCK, shared by every PHP
 *   process and server, released with the connection if a process dies. A request that cannot get
 *   it within {@see self::LOCK_WAIT_SECONDS} is refused (busy) rather than queued.
 * - After a 429, every call is refused without reaching Lichess until the pause ends
 *   (max(60 s, Retry-After)), the end instant being stored in the shared MySQL cache pool.
 * - 5xx and network errors: refused (down), nothing cached.
 *
 * Tokens are sent as bearer headers only and never logged.
 */
final class LichessGateway
{
    public const LOCK_NAME = 'chessmate.lichess';
    public const LOCK_WAIT_SECONDS = 3;
    public const PAUSE_SECONDS = 60;
    private const PAUSE_KEY = 'lichess.paused_until';

    public function __construct(
        private readonly Connection $connection,
        #[Autowire(service: 'repertoire.explorer_cache')]
        private readonly CacheItemPoolInterface $cache,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, scalar> $query
     *
     * @return array{status: int, body: array<mixed>|null, text: string|null} status 2xx or 4xx (except 429);
     *                                                                     body when JSON, text of a 200 otherwise
     *
     * @throws LichessUnavailableException
     */
    public function get(HttpClientInterface $client, string $path, array $query, #[\SensitiveParameter] ?string $token): array
    {
        $pausedUntil = $this->pausedUntil();
        if (null !== $pausedUntil) {
            throw new LichessUnavailableException(LichessUnavailableException::RATE_LIMITED, $pausedUntil - $this->now());
        }
        $locked = $this->connection->fetchOne('SELECT GET_LOCK(?, ?)', [self::LOCK_NAME, self::LOCK_WAIT_SECONDS], [ParameterType::STRING, ParameterType::INTEGER]);
        if (!is_numeric($locked) || 1 !== (int) $locked) {
            throw new LichessUnavailableException(LichessUnavailableException::BUSY, 5);
        }
        try {
            $response = $client->request('GET', $path, [
                'query' => $query,
                'headers' => null === $token ? [] : ['Authorization' => 'Bearer '.$token],
            ]);
            $status = $response->getStatusCode();
            if (429 === $status) {
                $retryAfter = (int) ($response->getHeaders(false)['retry-after'][0] ?? 0);
                $this->pause(max(self::PAUSE_SECONDS, $retryAfter));
                throw new LichessUnavailableException(LichessUnavailableException::RATE_LIMITED, max(self::PAUSE_SECONDS, $retryAfter));
            }
            if ($status >= 500) {
                throw new LichessUnavailableException(LichessUnavailableException::DOWN);
            }
            $body = $text = null;
            if (200 === $status) {
                $content = $response->getContent(false);
                $decoded = str_contains($response->getHeaders(false)['content-type'][0] ?? '', 'json') ? json_decode($content, true) : null;
                $body = \is_array($decoded) ? $decoded : null;
                $text = null === $body ? $content : null;
            }

            return ['status' => $status, 'body' => $body, 'text' => $text];
        } catch (ExceptionInterface $exception) {
            $this->logger->warning('Lichess did not answer.', ['path' => $path, 'exception' => $exception::class]);
            throw new LichessUnavailableException(LichessUnavailableException::DOWN);
        } finally {
            $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);
        }
    }

    /** End of the current pause (Unix time), null when there is none. */
    public function pausedUntil(): ?int
    {
        $item = $this->cache->getItem(self::PAUSE_KEY);
        $until = $item->isHit() ? $item->get() : null;

        return \is_int($until) && $until > $this->now() ? $until : null;
    }

    private function pause(int $seconds): void
    {
        $item = $this->cache->getItem(self::PAUSE_KEY);
        $item->set($this->now() + $seconds);
        $item->expiresAfter($seconds);
        $this->cache->save($item);
        $this->logger->notice('Lichess rate limit hit: pausing the requests.', ['seconds' => $seconds]);
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
