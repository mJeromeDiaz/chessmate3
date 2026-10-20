<?php

declare(strict_types=1);

namespace App\Enum\Vision;

/**
 * The question asked about the hidden position (docs/VISION.md, § 2). Stored as its value: add
 * cases, never rename one.
 */
enum Ask: string
{
    /** « Où est le roi noir ? »: a square holding that piece. */
    case Where = 'where';
    /** « Qu'y a-t-il en e5 ? »: the piece, or nothing. */
    case What = 'what';
    /** « Quelle pièce est en prise ? »: a square of a piece en prise. */
    case EnPrise = 'en_prise';
    /** « Quelles pièces blanches attaquent f7 ? »: every attacker's square. */
    case Attackers = 'attackers';
}
