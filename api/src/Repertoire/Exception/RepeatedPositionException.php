<?php

declare(strict_types=1);

namespace App\Repertoire\Exception;

/**
 * The move would lead back to a position the line comes from: the graph stays acyclic.
 */
final class RepeatedPositionException extends \InvalidArgumentException
{
}
