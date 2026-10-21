<?php

declare(strict_types=1);

namespace App\Calculation\Drawing;

/**
 * A question on the position a line reaches (docs/CALCULATION.md, § 2 bis): `where` a piece stands
 * (`target`: its FEN letter; `expected`: the squares holding it) or `what` stands on a square
 * (`target`: the square; `expected`: its FEN letter, or nothing for an empty square).
 */
final readonly class Question
{
    public const WHERE = 'where';
    public const WHAT = 'what';

    /**
     * @param list<string> $expected
     */
    public function __construct(
        public string $ask,
        public string $target,
        public string $fen,
        public array $expected,
    ) {
    }
}
