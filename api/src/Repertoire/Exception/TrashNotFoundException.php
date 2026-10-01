<?php

declare(strict_types=1);

namespace App\Repertoire\Exception;

final class TrashNotFoundException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('No such suite in the trash.');
    }
}
