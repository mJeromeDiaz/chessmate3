<?php

declare(strict_types=1);

namespace App\Chess\Pgn;

/**
 * A parsed PGN game (or study chapter): its tag pairs, its move tree ({@see Node}) and its result.
 */
final class Game
{
    /** @var array<string, string> tag name => value, in file order */
    public array $tags = [];

    public Node $root;

    /** "1-0", "0-1", "1/2-1/2", "*", or null when the movetext has none. */
    public ?string $result = null;

    public function __construct(public readonly int $line = 0)
    {
        $this->root = new Node();
    }

    /** The starting position when the game does not start from the initial one (FEN tag). */
    public function startingFen(): ?string
    {
        $fen = trim($this->tags['FEN'] ?? '');

        return '' === $fen ? null : $fen;
    }

    public function tag(string $name): ?string
    {
        return $this->tags[$name] ?? null;
    }
}
