<?php

declare(strict_types=1);

namespace App\Puzzle\Rating\Exception;

/**
 * The puzzle rating can only be seeded before the first rated attempt, and once.
 */
final class RatingAlreadyEstablishedException extends \RuntimeException
{
}
