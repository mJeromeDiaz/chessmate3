<?php

declare(strict_types=1);

namespace App\Vision\Drawing;

use App\Enum\Vision\Ask;

/**
 * A vision question drawn (docs/VISION.md, § 2): the position shown, the moves announced once it is
 * hidden (UCI), the question ($target: a piece letter for « where », a square otherwise; $side for
 * « attackers ») and what answers it ($expected: squares, or the piece letter, '' for an empty
 * square).
 */
final readonly class Draw
{
    /**
     * @param list<string> $announced
     * @param list<string> $expected
     */
    public function __construct(
        public string $fen,
        public array $announced,
        public Ask $ask,
        public ?string $target,
        public ?string $side,
        public array $expected,
        public ?int $puzzleId,
        public int $visibleSeconds,
    ) {
    }
}
