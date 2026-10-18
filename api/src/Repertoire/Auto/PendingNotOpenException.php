<?php

declare(strict_types=1);

namespace App\Repertoire\Auto;

/**
 * A pending position that cannot do that now (docs/REPERTOIRE.md, "En suspens"): answered or
 * dismissed already, or restored while not dismissed.
 */
final class PendingNotOpenException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('pending_not_open');
    }
}
