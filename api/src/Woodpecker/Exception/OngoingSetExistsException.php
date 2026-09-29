<?php

declare(strict_types=1);

namespace App\Woodpecker\Exception;

/**
 * The user already has an active or paused set (one at a time).
 */
final class OngoingSetExistsException extends \RuntimeException
{
}
