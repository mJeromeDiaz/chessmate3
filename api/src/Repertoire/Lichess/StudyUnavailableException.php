<?php

declare(strict_types=1);

namespace App\Repertoire\Lichess;

/**
 * Lichess did not give the study: private or unlisted without a study:read token (or missing),
 * not found with one, or over the import size limit.
 */
final class StudyUnavailableException extends \RuntimeException
{
    /** Private, unlisted or missing: the user may grant study:read and try again. */
    public const PRIVATE = 'study_private';
    public const NOT_FOUND = 'study_not_found';
    public const TOO_LARGE = 'too_large';

    public function __construct(public readonly string $reason)
    {
        parent::__construct(sprintf('Study unavailable: %s.', $reason));
    }
}
