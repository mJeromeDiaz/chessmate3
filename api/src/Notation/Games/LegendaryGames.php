<?php

declare(strict_types=1);

namespace App\Notation\Games;

use App\Chess\Pgn\Parser;
use App\Chess\Rules;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The legendary games of the notation module (docs/NOTATION.md, § 3), from data/legendary-games.json
 * (written by the user: id, white, black, event, year, pgn): each PGN's main line replayed move by
 * move; a game whose PGN does not replay to its end is left out (LegendaryGamesTest checks none
 * is).
 */
final class LegendaryGames
{
    /** @var array<int, LegendaryGame>|null by id */
    private ?array $games = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/data/legendary-games.json')]
        private readonly string $path,
    ) {
    }

    /**
     * @return array<int, LegendaryGame> by id, in the file's order
     */
    public function all(): array
    {
        return $this->games ??= $this->load();
    }

    public function find(int $id): ?LegendaryGame
    {
        return $this->all()[$id] ?? null;
    }

    /**
     * @return array<int, LegendaryGame>
     */
    private function load(): array
    {
        $json = file_get_contents($this->path);
        $rows = false === $json ? [] : json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        $games = [];
        foreach (\is_array($rows) ? $rows : [] as $row) {
            if (!\is_array($row) || !\is_int($row['id'] ?? null) || !\is_string($row['pgn'] ?? null)) {
                continue;
            }
            $moves = self::moves($row['pgn']);
            if (null === $moves) {
                continue;
            }
            $games[$row['id']] = new LegendaryGame(
                $row['id'],
                \is_string($row['white'] ?? null) ? $row['white'] : '?',
                \is_string($row['black'] ?? null) ? $row['black'] : '?',
                \is_string($row['event'] ?? null) ? $row['event'] : '',
                \is_int($row['year'] ?? null) ? $row['year'] : null,
                $moves,
            );
        }

        return $games;
    }

    /**
     * The main line of a PGN as UCI moves, null when it does not replay from the initial position.
     *
     * @return list<string>|null
     */
    public static function moves(string $pgn): ?array
    {
        $parsed = (new Parser())->parse($pgn)[0] ?? null;
        if (null === $parsed || null !== $parsed->startingFen()) {
            return null;
        }
        $rules = Rules::initial();
        $moves = [];
        for ($node = $parsed->root->mainChild(); null !== $node; $node = $node->mainChild()) {
            $move = $rules->playSan($node->san);
            if (null === $move) {
                return null;
            }
            $moves[] = Rules::uci($move);
        }

        return [] === $moves ? null : $moves;
    }
}
