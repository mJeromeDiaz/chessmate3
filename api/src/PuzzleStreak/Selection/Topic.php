<?php

declare(strict_types=1);

namespace App\PuzzleStreak\Selection;

use App\PuzzleStreak\StreakRules;

/**
 * What a puzzle streak draws from (docs/PUZZLE_STREAK.md), as stored and sent: "general" (the
 * whole catalogue), "theme:<key>" (a key of {@see StreakRules::TOPICS}) or "opening:<tag>:<w|b>"
 * (an opening family's catalogue tag, and the side the player plays in it). Pure.
 */
final readonly class Topic
{
    public const GENERAL = 'general';
    public const MAX_LENGTH = 80;

    /**
     * @param list<string> $themes the Lichess theme keys (OR) of a theme topic, empty otherwise
     */
    private function __construct(
        public string $key,
        public array $themes = [],
        /** The family's catalogue tag ("Italian_Game") of an opening topic. */
        public ?string $family = null,
        /** "w" or "b": the side the player has in an opening topic's puzzles. */
        public ?string $solverColor = null,
    ) {
    }

    /**
     * @throws \InvalidArgumentException not a topic
     */
    public static function parse(string $key): self
    {
        if (self::GENERAL === $key) {
            return new self($key);
        }
        if (str_starts_with($key, 'theme:')) {
            $topic = StreakRules::TOPICS[substr($key, 6)] ?? null;
            if (null !== $topic) {
                return new self($key, $topic[1]);
            }
        }
        if (\strlen($key) <= self::MAX_LENGTH && 1 === preg_match('/^opening:([A-Za-z0-9_-]{1,64}):([wb])$/', $key, $match)) {
            return new self($key, [], $match[1], $match[2]);
        }

        throw new \InvalidArgumentException('topic must be "general", "theme:<key>" or "opening:<family>:<w|b>".');
    }

    public static function opening(string $family, string $solverColor): self
    {
        return self::parse(\sprintf('opening:%s:%s', $family, $solverColor));
    }

    public function isOpening(): bool
    {
        return null !== $this->family;
    }
}
