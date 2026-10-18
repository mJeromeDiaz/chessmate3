<?php

declare(strict_types=1);

namespace App\Repertoire\Auto;

use App\Repertoire\Limits;

/**
 * Where the exploration of an analysis stands (docs/REPERTOIRE.md, "Repertoire from my games"):
 * the rule frozen at its start, the explorer's replies of every position asked so far (by
 * normalized FEN: a transposition is never asked twice), the real Lichess calls made and allowed.
 * At its cap the exploration stops and keeps all this: a resume allows another
 * {@see Limits::$autoMaxExplorerCalls} calls and goes on from there. "Explore further" on a line
 * has its own count, so that it never uses the analysis' calls. Stored as
 * repertoire_auto_build.exploration.
 *
 * @phpstan-import-type Replies from ReplyRule
 */
final class Exploration
{
    /**
     * @param array<string, Replies> $replies normalized FEN => the explorer's replies
     * @param array<string, int>     $lines   line key ("family|cut") => its own calls
     */
    public function __construct(
        public ReplyRule $rule,
        public array $replies = [],
        public int $calls = 0,
        public int $budget = 0,
        public bool $capped = false,
        public array $lines = [],
    ) {
    }

    /**
     * @param array<string, mixed>|null $stored
     */
    public static function fromStored(?array $stored, Limits $limits): self
    {
        if (null === $stored) {
            return new self(ReplyRule::fromLimits($limits), budget: $limits->autoMaxExplorerCalls);
        }
        $replies = [];
        foreach (\is_array($stored['replies'] ?? null) ? $stored['replies'] : [] as $fen => $answer) {
            $answer = ReplyRule::fromJson($answer);
            if (null !== $answer) {
                $replies[(string) $fen] = $answer;
            }
        }
        $lines = [];
        foreach (\is_array($stored['lines'] ?? null) ? $stored['lines'] : [] as $key => $calls) {
            if (\is_int($calls)) {
                $lines[(string) $key] = $calls;
            }
        }
        $rule = \is_array($stored['rule'] ?? null) ? $stored['rule'] : null;

        return new self(
            ReplyRule::fromStored($rule, $limits),
            $replies,
            \is_int($stored['calls'] ?? null) ? $stored['calls'] : 0,
            \is_int($stored['budget'] ?? null) ? $stored['budget'] : $limits->autoMaxExplorerCalls,
            true === ($stored['capped'] ?? false),
            $lines,
        );
    }

    /**
     * @return array{rule: array<string, mixed>, replies: array<string, Replies>, calls: int, budget: int, capped: bool, lines: array<string, int>}
     */
    public function toArray(): array
    {
        return ['rule' => $this->rule->toArray(), 'replies' => $this->replies, 'calls' => $this->calls, 'budget' => $this->budget, 'capped' => $this->capped, 'lines' => $this->lines];
    }

    /** Another round of calls after the cap: the exploration goes on from where it stopped. */
    public function resume(int $calls): void
    {
        if ($this->capped) {
            $this->budget = $this->calls + $calls;
            $this->capped = false;
        }
    }
}
