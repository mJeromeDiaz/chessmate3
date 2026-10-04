<?php

declare(strict_types=1);

namespace App\Training\Exception;

/**
 * Another training session of the user is still in progress.
 */
final class SessionInProgressException extends \RuntimeException
{
}
