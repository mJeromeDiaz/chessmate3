<?php

declare(strict_types=1);

namespace App\Training\Exception;

/**
 * The item can no longer be submitted, though the run goes on (e.g. the classic cycle run it
 * belonged to was lost meanwhile): the client asks for the next item.
 */
final class ItemClosedException extends \RuntimeException
{
}
