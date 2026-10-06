<?php

declare(strict_types=1);

namespace App\State\Puzzle;

use App\Puzzle\Selection\SelectionUnavailableException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * 503 of a themed draw while the selection index is rebuilt (after a catalogue import): expected,
 * logged as a warning (config/packages/framework.yaml `exceptions`) instead of the critical level of
 * a 5xx.
 *
 * Outside debug, API Platform replaces the detail of any 5xx: the reason travels in the
 * {@see self::HEADER} header, exposed to the SPA by CORS.
 */
final class PuzzleMaintenanceHttpException extends ServiceUnavailableHttpException
{
    public const HEADER = 'X-Puzzle-Maintenance';

    public static function from(SelectionUnavailableException $exception): self
    {
        return new self(SelectionUnavailableException::RETRY_AFTER, $exception->getMessage(), $exception, 0, [self::HEADER => 'rebuilding']);
    }
}
