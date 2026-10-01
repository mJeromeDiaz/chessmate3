<?php

declare(strict_types=1);

namespace App\Training\Exception;

/**
 * The module's options (the run's config) are invalid: the message says which (422).
 */
final class InvalidRunConfigException extends \RuntimeException
{
}
