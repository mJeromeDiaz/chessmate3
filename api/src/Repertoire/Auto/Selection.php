<?php

declare(strict_types=1);

namespace App\Repertoire\Auto;

/**
 * The user's choices on a build (docs/REPERTOIRE.md, "Repertoire from my games"): the opening
 * families kept, and per family the moves they chose instead of their most played ones and the
 * lines they removed (node paths, {@see Tree}). Stored as repertoire_auto_build.selection.
 *
 * @phpstan-type SelectionArray array{families: list<string>, choices: array<string, array<string, string>>, removed: array<string, list<string>>}
 */
final readonly class Selection
{
    /** A node path: UCI moves joined by spaces. */
    public const PATH_PATTERN = '/^[a-h][1-8][a-h][1-8][qrbn]?( [a-h][1-8][a-h][1-8][qrbn]?){0,79}$/';
    public const UCI_PATTERN = '/^[a-h][1-8][a-h][1-8][qrbn]?$/';
    /** Choices and removals kept per family. */
    public const MAX_PER_FAMILY = 500;

    /**
     * @param list<string>                         $families
     * @param array<string, array<string, string>> $choices  family key => node path => UCI
     * @param array<string, list<string>>          $removed  family key => node paths
     */
    public function __construct(
        public array $families = [],
        public array $choices = [],
        public array $removed = [],
    ) {
    }

    /**
     * The selection as asked, checked against the families the build offers: unknown families,
     * malformed paths or moves and choices or removals of a family not kept are refused.
     *
     * @param list<string> $offered the family keys of the synthesis
     *
     * @throws AutoBuildException invalid_selection
     */
    public static function checked(mixed $families, mixed $choices, mixed $removed, array $offered): self
    {
        $invalid = new AutoBuildException(AutoBuildException::INVALID_SELECTION);
        if (!\is_array($families) || !\is_array($choices) || !\is_array($removed)) {
            throw $invalid;
        }
        $kept = [];
        foreach ($families as $family) {
            if (!\is_string($family) || !\in_array($family, $offered, true)) {
                throw $invalid;
            }
            $kept[$family] = true;
        }

        $checkedChoices = [];
        foreach ($choices as $family => $moves) {
            if (!isset($kept[$family]) || !\is_array($moves) || \count($moves) > self::MAX_PER_FAMILY) {
                throw $invalid;
            }
            foreach ($moves as $path => $uci) {
                // The empty path is the initial position (White's first move).
                $validPath = '' === (string) $path || 1 === preg_match(self::PATH_PATTERN, (string) $path);
                if (!$validPath || !\is_string($uci) || 1 !== preg_match(self::UCI_PATTERN, $uci)) {
                    throw $invalid;
                }
                $checkedChoices[(string) $family][(string) $path] = $uci;
            }
        }

        $checkedRemoved = [];
        foreach ($removed as $family => $paths) {
            if (!isset($kept[$family]) || !\is_array($paths) || \count($paths) > self::MAX_PER_FAMILY) {
                throw $invalid;
            }
            foreach ($paths as $path) {
                if (!\is_string($path) || 1 !== preg_match(self::PATH_PATTERN, $path)) {
                    throw $invalid;
                }
                $checkedRemoved[(string) $family][] = $path;
            }
        }

        return new self(array_keys($kept), $checkedChoices, $checkedRemoved);
    }

    /**
     * Reads back {@see self::toArray()} (null: nothing chosen yet).
     *
     * @param array<string, mixed>|null $stored
     */
    public static function fromStored(?array $stored): self
    {
        if (null === $stored) {
            return new self();
        }
        $families = array_values(array_filter(\is_array($stored['families'] ?? null) ? $stored['families'] : [], 'is_string'));
        $choices = [];
        foreach (\is_array($stored['choices'] ?? null) ? $stored['choices'] : [] as $family => $moves) {
            foreach (\is_array($moves) ? $moves : [] as $path => $uci) {
                if (\is_string($uci)) {
                    $choices[(string) $family][(string) $path] = $uci;
                }
            }
        }
        $removed = [];
        foreach (\is_array($stored['removed'] ?? null) ? $stored['removed'] : [] as $family => $paths) {
            $removed[(string) $family] = array_values(array_filter(\is_array($paths) ? $paths : [], 'is_string'));
        }

        return new self($families, $choices, $removed);
    }

    /**
     * @return SelectionArray
     */
    public function toArray(): array
    {
        return ['families' => $this->families, 'choices' => $this->choices, 'removed' => $this->removed];
    }
}
