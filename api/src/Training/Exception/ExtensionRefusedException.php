<?php

declare(strict_types=1);

namespace App\Training\Exception;

/**
 * The run cannot be extended: its module has no overtime, or the run is not waiting for the
 * player's decision (time not up, nothing to go on with, decision time over).
 */
final class ExtensionRefusedException extends \RuntimeException
{
}
