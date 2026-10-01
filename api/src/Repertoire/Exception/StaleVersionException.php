<?php

declare(strict_types=1);

namespace App\Repertoire\Exception;

/**
 * The change was based on an older version of the repertoire (another tab changed it): the client
 * reloads, nothing was applied.
 */
final class StaleVersionException extends \RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct(sprintf('The repertoire changed meanwhile (version %d).', $currentVersion));
    }
}
