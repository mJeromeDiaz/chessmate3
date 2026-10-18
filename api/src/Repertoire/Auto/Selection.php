<?php

declare(strict_types=1);

namespace App\Repertoire\Auto;

/**
 * The user's choices on a build (docs/REPERTOIRE.md, "Repertoire from my games"): the opening
 * families kept, and per family the moves they chose instead of their most played ones, the
 * lines they removed (node paths, {@see Tree}), and their answer to the short lines: kept (the
 * line's path) or set aside as pending (the line's cut, removed like a line). Stored as
 * repertoire_auto_build.selection.
 *
 * @phpstan-type SelectionArray array{families: list<string>, choices: array<string, array<string, string>>, removed: array<string, list<string>>, kept: array<string, list<string>>, suspended: array<string, list<string>>}
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
     * @param array<string, list<string>>          $kept      family key => paths of the short lines kept
     * @param array<string, list<string>>          $suspended family key => cuts of the short lines set aside
     */
    public function __construct(
        public array $families = [],
        public array $choices = [],
        public array $removed = [],
        public array $kept = [],
        public array $suspended = [],
    ) {
    }

    /**
     * The selection as asked, checked against the families the build offers: unknown families,
     * malformed paths or moves and choices, removals or short-line answers of a family not kept are
     * refused.
     *
     * @param list<string> $offered the family keys of the synthesis
     *
     * @throws AutoBuildException invalid_selection
     */
    public static function checked(mixed $families, mixed $choices, mixed $removed, array $offered, mixed $kept = [], mixed $suspended = []): self
    {
        $invalid = new AutoBuildException(AutoBuildException::INVALID_SELECTION);
        if (!\is_array($families) || !\is_array($choices) || !\is_array($removed) || !\is_array($kept) || !\is_array($suspended)) {
            throw $invalid;
        }
        $keptFamilies = [];
        foreach ($families as $family) {
            if (!\is_string($family) || !\in_array($family, $offered, true)) {
                throw $invalid;
            }
            $keptFamilies[$family] = true;
        }

        $checkedChoices = [];
        foreach ($choices as $family => $moves) {
            if (!isset($keptFamilies[$family]) || !\is_array($moves) || \count($moves) > self::MAX_PER_FAMILY) {
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

        return new self(
            array_keys($keptFamilies),
            $checkedChoices,
            self::checkedPaths($removed, $keptFamilies, $invalid),
            self::checkedPaths($kept, $keptFamilies, $invalid),
            self::checkedPaths($suspended, $keptFamilies, $invalid),
        );
    }

    /**
     * @param array<mixed>        $byFamily family key => node paths
     * @param array<string, true> $families the families kept
     *
     * @return array<string, list<string>>
     *
     * @throws AutoBuildException
     */
    private static function checkedPaths(array $byFamily, array $families, AutoBuildException $invalid): array
    {
        $checked = [];
        foreach ($byFamily as $family => $paths) {
            if (!isset($families[$family]) || !\is_array($paths) || \count($paths) > self::MAX_PER_FAMILY) {
                throw $invalid;
            }
            foreach ($paths as $path) {
                if (!\is_string($path) || 1 !== preg_match(self::PATH_PATTERN, $path)) {
                    throw $invalid;
                }
                $checked[(string) $family][] = $path;
            }
        }

        return $checked;
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
        return new self($families, $choices, self::storedPaths($stored['removed'] ?? null), self::storedPaths($stored['kept'] ?? null), self::storedPaths($stored['suspended'] ?? null));
    }

    /**
     * @return array<string, list<string>>
     */
    private static function storedPaths(mixed $byFamily): array
    {
        $paths = [];
        foreach (\is_array($byFamily) ? $byFamily : [] as $family => $list) {
            $paths[(string) $family] = array_values(array_filter(\is_array($list) ? $list : [], 'is_string'));
        }

        return $paths;
    }

    /**
     * @return SelectionArray
     */
    public function toArray(): array
    {
        return ['families' => $this->families, 'choices' => $this->choices, 'removed' => $this->removed, 'kept' => $this->kept, 'suspended' => $this->suspended];
    }
}
