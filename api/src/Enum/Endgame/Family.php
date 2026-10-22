<?php

declare(strict_types=1);

namespace App\Enum\Endgame;

/**
 * The themes of the endgames module (docs/ENDGAMES.md, § 3), as the « Finales » tile of the
 * session builder names them. Stored as their value: add cases, never rename one.
 */
enum Family: string
{
    case BasicMates = 'basicMates';
    case Pawns = 'pawns';
    case Queens = 'queens';
    case Rooks = 'rooks';
    case Bishops = 'bishops';
    case Knights = 'knights';
}
