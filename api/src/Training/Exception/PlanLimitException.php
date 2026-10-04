<?php

declare(strict_types=1);

namespace App\Training\Exception;

/**
 * The user already has the maximum number of saved sessions.
 */
final class PlanLimitException extends \RuntimeException
{
}
