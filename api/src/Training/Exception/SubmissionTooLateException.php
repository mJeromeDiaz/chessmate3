<?php

declare(strict_types=1);

namespace App\Training\Exception;

/**
 * The run's time was up when the submission arrived.
 */
final class SubmissionTooLateException extends \RuntimeException
{
}
