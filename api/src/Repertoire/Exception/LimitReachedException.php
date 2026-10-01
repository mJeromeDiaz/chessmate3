<?php

declare(strict_types=1);

namespace App\Repertoire\Exception;

/**
 * A limit of {@see \App\Repertoire\Limits} would be exceeded. $limit: repertoires, positions or depth.
 */
final class LimitReachedException extends \RuntimeException
{
    public function __construct(public readonly string $limit, public readonly int $max)
    {
        parent::__construct(sprintf('Limit reached: %d %s at most.', $max, $limit));
    }
}
