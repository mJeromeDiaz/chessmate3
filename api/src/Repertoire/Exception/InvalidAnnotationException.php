<?php

declare(strict_types=1);

namespace App\Repertoire\Exception;

/**
 * Comment or NAGs not accepted (length, range, contradictory move assessments).
 */
final class InvalidAnnotationException extends \InvalidArgumentException
{
}
