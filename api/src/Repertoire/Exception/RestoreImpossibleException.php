<?php

declare(strict_types=1);

namespace App\Repertoire\Exception;

/**
 * The suite cannot be restored: the position it starts from is no longer in the repertoire.
 */
final class RestoreImpossibleException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('The position this suite starts from is no longer in the repertoire.');
    }
}
