<?php

declare(strict_types=1);

namespace App\Enum\Vision;

/**
 * What a vision run asks (docs/VISION.md, § 2), its config `kind`. Stored as its value: add cases,
 * never rename one.
 */
enum Kind: string
{
    /** A generated position remembered: where is a piece, what stands on a square. */
    case Locate = 'locate';
    /** A puzzle's position remembered, 3 moves announced: a piece en prise. */
    case EnPrise = 'en_prise';
    /** A puzzle's position remembered, 3 moves announced: the pieces attacking a square. */
    case Attackers = 'attackers';
    /** A rook or a bishop moves 2 or 3 times, the last a capture: what did it take? (3.1.1) */
    case TrackCapture = 'track_capture';
    /** A piece moves 2 to 4 times, no capture: where is it now? (3.1.2) */
    case TrackSquare = 'track_square';
    /** No position: a knight's fewest jumps between two squares. (3.1.3) */
    case KnightDistance = 'knight_distance';
    /** A piece moves 3 to 5 times, turning each time, 1 or 2 captures: where is it now? (3.1.4) */
    case TrackTurns = 'track_turns';
    /** A piece moves 5 to 8 times: where is it, what did it take, did it stop on a square? (3.1.5) */
    case TrackLong = 'track_long';
    /** A piece moves 4 to 6 times, then three questions on that path (the mini-boss 3.1). */
    case TrackBoss = 'track_boss';

    /** A generated position, 3 or 4 moves of both sides, one capture: which piece is gone? (3.2.3) */
    case UpdateMissing = 'update_missing';
    /** 5 or 6 moves, two captures: where were the pieces taken? (3.2.4) */
    case UpdateCaptures = 'update_captures';
    /** 5 to 8 moves: what stands on a square, where is a piece, can a piece go there? (3.2.5) */
    case UpdateMixed = 'update_mixed';
    /** 5 moves, then three questions on the position reached (the mini-boss 3.2). */
    case UpdateBoss = 'update_boss';

    /** Questions on a piece's path: only that piece moves (docs/VISION.md, § 2 bis). */
    public function isTrack(): bool
    {
        return \in_array($this, [self::TrackCapture, self::TrackSquare, self::TrackTurns, self::TrackLong, self::TrackBoss], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }
}
