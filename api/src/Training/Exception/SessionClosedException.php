<?php

declare(strict_types=1);

namespace App\Training\Exception;

/**
 * The session is over (completed, abandoned or past its day).
 */
final class SessionClosedException extends \RuntimeException
{
}
