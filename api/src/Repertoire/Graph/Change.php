<?php

declare(strict_types=1);

namespace App\Repertoire\Graph;

/**
 * What a change of a repertoire did ({@see GraphEditor}): the new version and every position and
 * move whose data changed, derived data included (canonical flag, depth, segment), for the client
 * to update its copy. Ids are RFC 4122 strings.
 */
final class Change
{
    /** @var array<string, true> */
    public array $positions = [];
    /** @var array<string, true> */
    public array $moves = [];
    /** @var array<string, true> */
    public array $deletedPositions = [];
    /** @var array<string, true> */
    public array $deletedMoves = [];

    /** The move added by addMove (existing one when it was already there). */
    public ?string $moveId = null;
    /** addMove reached a position already in the repertoire through another move order. */
    public bool $transposition = false;
    /** The trash entry a replacement or a deletion created. */
    public ?string $trashId = null;

    public function __construct(
        public int $version,
        /** add, replace, delete, promote, annotate, import, restore, undo; none when nothing changed */
        public string $operation,
    ) {
    }

    /**
     * @param iterable<string> $ids
     */
    public function touchMoves(iterable $ids): void
    {
        foreach ($ids as $id) {
            if (!isset($this->deletedMoves[$id])) {
                $this->moves[$id] = true;
            }
        }
    }

    /**
     * @param iterable<string> $ids
     */
    public function touchPositions(iterable $ids): void
    {
        foreach ($ids as $id) {
            if (!isset($this->deletedPositions[$id])) {
                $this->positions[$id] = true;
            }
        }
    }

    /**
     * @param iterable<string> $moves
     * @param iterable<string> $positions
     */
    public function delete(iterable $moves, iterable $positions): void
    {
        foreach ($moves as $id) {
            $this->deletedMoves[$id] = true;
            unset($this->moves[$id]);
        }
        foreach ($positions as $id) {
            $this->deletedPositions[$id] = true;
            unset($this->positions[$id]);
        }
    }
}
