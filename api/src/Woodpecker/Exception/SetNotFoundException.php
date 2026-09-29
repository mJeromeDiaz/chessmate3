<?php

declare(strict_types=1);

namespace App\Woodpecker\Exception;

/**
 * No such set for this user (another user's set is reported the same way).
 */
final class SetNotFoundException extends \RuntimeException
{
}
