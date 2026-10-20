<?php

declare(strict_types=1);

namespace App\MindMap\Boss;

use App\Coordinates\Series\SquareDrawer;
use App\Enum\MindMap\BossQuestion;
use Random\Randomizer;

/**
 * Draws the Jongleur's questions (docs/MIND_MAP.md, § 8): each kind as often as the others (one of
 * the least asked so far), never the same square twice in a row.
 */
final readonly class QuestionDrawer
{
    private Randomizer $randomizer;

    public function __construct(?Randomizer $randomizer = null)
    {
        $this->randomizer = $randomizer ?? new Randomizer();
    }

    /**
     * @param list<BossQuestion> $asked    the questions of the fight so far
     * @param list<BossQuestion> $excluded kinds that cannot be asked now (no puzzle to draw)
     */
    public function question(array $asked, array $excluded = []): BossQuestion
    {
        $counts = [];
        foreach (BossQuestion::cases() as $question) {
            if (!\in_array($question, $excluded, true)) {
                $counts[$question->value] = 0;
            }
        }
        if ([] === $counts) {
            throw new \LogicException('Every question excluded.');
        }
        foreach ($asked as $question) {
            if (isset($counts[$question->value])) {
                ++$counts[$question->value];
            }
        }
        $least = array_keys($counts, min($counts), true);

        return BossQuestion::from((string) $least[$this->randomizer->getInt(0, \count($least) - 1)]);
    }

    public function square(?string $previous): string
    {
        do {
            $square = SquareDrawer::name($this->randomizer->getInt(0, 63));
        } while ($square === $previous);

        return $square;
    }
}
