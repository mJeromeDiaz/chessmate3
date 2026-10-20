<?php

declare(strict_types=1);

namespace App\Notation\Games;

/**
 * A legendary game (docs/NOTATION.md, § 3, « game_write », « game_play »): its players, its event
 * and year, and its moves (UCI, from the initial position).
 */
final readonly class LegendaryGame
{
    /**
     * @param list<string> $moves
     */
    public function __construct(
        public int $id,
        public string $white,
        public string $black,
        public string $event,
        public ?int $year,
        public array $moves,
    ) {
    }

    /** « Paul Morphy – Duc de Brunswick, Paris 1858 ». */
    public function label(): string
    {
        return trim(\sprintf('%s – %s, %s %s', $this->white, $this->black, $this->event, $this->year ?? ''));
    }
}
