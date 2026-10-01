<?php

declare(strict_types=1);

namespace App\Repertoire\Exception;

/**
 * The repertoire does not exist, or belongs to another user.
 */
final class RepertoireNotFoundException extends \RuntimeException
{
}
