<?php

declare(strict_types=1);

namespace App\Calculation\Drawing;

use App\Calculation\CalculationRules;
use App\Enum\Calculation\Ending;

/**
 * What line to draw (docs/CALCULATION.md, § 2): the player's moves it may have, how it ends (any
 * when null), Lichess themes (OR; none: by its length or its mate) and a capture on the way.
 */
final readonly class LineSpec
{
    /**
     * @param non-empty-list<int> $moves  1 to CalculationRules::MAX_MATE each
     * @param list<string>        $themes
     */
    public function __construct(
        public array $moves,
        public ?Ending $ending = null,
        public array $themes = [],
        public bool $capture = false,
    ) {
    }

    /** A mate in $mate (chapter 1, the Métronome). */
    public static function mate(int $mate, bool $capture = false): self
    {
        return new self([$mate], Ending::Mate, [], $capture);
    }

    /**
     * The themes to draw from: those asked, else the mates' (mateIn3) or the lengths' (long).
     *
     * @return list<string>
     */
    public function drawThemes(): array
    {
        if ([] !== $this->themes) {
            return $this->themes;
        }

        return array_values(array_unique(array_map(
            fn (int $moves): string => Ending::Mate === $this->ending ? CalculationRules::theme($moves) : CalculationRules::lengthTheme($moves),
            $this->moves,
        )));
    }
}
