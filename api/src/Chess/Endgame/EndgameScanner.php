<?php

declare(strict_types=1);

namespace App\Chess\Endgame;

use App\Enum\Chess\EndgameType;
use PChess\Chess\Move;

/**
 * Follows a game from the initial position, move by move, and keeps where it entered an endgame
 * ({@see Material::isEndgame()}): its kind there, after how many plies, the material balance
 * (White's point of view). The endgame begins after the first quiet move (no capture, no
 * promotion) below the threshold, so that an exchange is over before the balance is read (after
 * "Qxg5" the queen up is only the exchange half done). Pure.
 */
final class EndgameScanner
{
    private Material $material;
    private int $plies = 0;
    private ?EndgameType $type = null;
    private ?int $ply = null;
    private ?int $balance = null;

    public function __construct()
    {
        $this->material = new Material();
    }

    public function play(Move $move): void
    {
        ++$this->plies;
        if (null !== $this->ply) {
            return;
        }
        $this->material->play($move->turn, $move->captured, $move->promotion);
        if (null === $move->captured && null === $move->promotion && $this->material->isEndgame()) {
            $this->type = $this->material->type();
            $this->ply = $this->plies;
            $this->balance = $this->material->balance();
        }
    }

    /** The kind of endgame the game entered, null if it never did. */
    public function type(): ?EndgameType
    {
        return $this->type;
    }

    /** Plies played when the endgame began. */
    public function ply(): ?int
    {
        return $this->ply;
    }

    /** White's points minus Black's when the endgame began. */
    public function balance(): ?int
    {
        return $this->balance;
    }
}
