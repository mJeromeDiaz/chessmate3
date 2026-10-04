<?php

declare(strict_types=1);

namespace App\Training\Exception;

/**
 * Unknown session, or another user's.
 */
final class SessionNotFoundException extends \RuntimeException
{
}
