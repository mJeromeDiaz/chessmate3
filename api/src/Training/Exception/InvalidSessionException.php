<?php

declare(strict_types=1);

namespace App\Training\Exception;

/**
 * A step of the program is invalid: the message says which (422).
 */
final class InvalidSessionException extends \RuntimeException
{
}
