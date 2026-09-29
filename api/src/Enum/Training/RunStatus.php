<?php

declare(strict_types=1);

namespace App\Enum\Training;

enum RunStatus: string
{
    case Active = 'active';
    case Closed = 'closed';
}
