<?php

declare(strict_types=1);

namespace App\Puzzle\Attempt\Exception;

/**
 * No puzzle matches these criteria, even in the widest rating window.
 */
final class NoPuzzleAvailableException extends \RuntimeException
{
}
