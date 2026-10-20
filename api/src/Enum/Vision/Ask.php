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
    /** « Où est la tour maintenant ? »: the square the tracked piece stopped on last. */
    case Arrival = 'arrival';
    /** « Quelle pièce la tour a-t-elle prise en d7 ? »: the piece taken there. */
    case Captured = 'captured';
    /** « Combien de sauts… de b1 à f7 ? »: a number (a choice). */
    case KnightJumps = 'knight_jumps';
    /** « Est-elle passée par e4 ? »: yes or no (a choice). */
    case Passed = 'passed';
    /** « Où étaient les pièces qu'elle a prises ? »: every square it captured on. */
    case Captures = 'captures';
    /** « Où s'est-elle posée ? »: every square it stopped on, the last one included. */
    case Stops = 'stops';
    /** « Quelle pièce a disparu ? »: the piece taken (a choice: its FEN letter). */
    case Missing = 'missing';
    /** « Le fou blanc peut-il aller en c4 en un coup ? »: yes or no (a choice). */
    case Reach = 'reach';

    /** « Quelles pièces noires sont attaquées ? »: every one ($side), the king never. */
    case Attacked = 'attacked';
    /** « Quelles pièces blanches sont défendues ? »: every one ($side), the king never. */
    case Defended = 'defended';
    /** « …sont sans défense ? » */
    case Undefended = 'undefended';
    /** « Quelles pièces sont alignées avec la tour blanche ? »: the first piece in each direction. */
    case Aligned = 'aligned';
    /** « Quelles pièces noires sont en prise ? »: every one ($side, the side not to move). */
    case EnPriseAll = 'en_prise_all';
    /** « Quelles prises sont gagnantes ? »: the same pieces, told apart from those only attacked. */
    case Winning = 'winning';

    /** Answered with a choice (a number, yes or no, a piece's letter), not on the board. */
    public function isChoice(): bool
    {
        return \in_array($this, [self::KnightJumps, self::Passed, self::Missing, self::Reach], true);
    }
}
