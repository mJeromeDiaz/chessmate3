<?php

declare(strict_types=1);

namespace App\State\Repertoire;

use App\Repertoire\Lichess\LichessUnavailableException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * 503 of the Lichess proxy (explorer, cloud eval): an expected outage of a third party, logged as a
 * warning (config/packages/framework.yaml `exceptions`) instead of the critical level of a 5xx.
 *
 * Outside debug, API Platform replaces the detail of any 5xx: the reason (rate_limited, busy, down,
 * no_token) travels in the {@see self::REASON_HEADER} header, exposed to the SPA by CORS.
 */
final class LichessUnavailableHttpException extends ServiceUnavailableHttpException
{
    public const REASON_HEADER = 'X-Lichess-Unavailable';

    public static function from(LichessUnavailableException $exception): self
    {
        return new self($exception->retryAfter, $exception->getMessage(), $exception, 0, [self::REASON_HEADER => $exception->reason]);
    }
}
