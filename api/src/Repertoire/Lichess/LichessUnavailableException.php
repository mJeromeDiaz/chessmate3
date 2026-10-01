<?php

declare(strict_types=1);

namespace App\Repertoire\Lichess;

/**
 * Lichess cannot answer now: rate limited (we wait before asking again), busy (one request at a
 * time), down, or no token to call it with. The editor keeps working without the panel.
 */
final class LichessUnavailableException extends \RuntimeException
{
    public const RATE_LIMITED = 'rate_limited';
    public const BUSY = 'busy';
    public const DOWN = 'down';
    public const NO_TOKEN = 'no_token';

    public function __construct(
        public readonly string $reason,
        /** Seconds before trying again, when known. */
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct(match ($reason) {
            self::RATE_LIMITED => 'Lichess limits requests for now: try again in a minute.',
            self::BUSY => 'Lichess is busy with other requests: try again in a moment.',
            self::NO_TOKEN => 'The Lichess explorer needs a Lichess account: link yours in your profile.',
            default => 'Lichess does not answer for now.',
        });
    }
}
