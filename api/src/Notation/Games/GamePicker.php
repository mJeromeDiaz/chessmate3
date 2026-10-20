<?php

declare(strict_types=1);

namespace App\Notation\Games;

use App\Chess\Rules;
use App\Entity\Training\Run;
use App\Notation\Drawing\Exercise;
use App\Notation\NotationRules;
use App\Repository\Notation\AttemptRepository;
use Random\Randomizer;

/**
 * The next item of a legendary game (docs/NOTATION.md, § 3): the game of the run goes on from
 * where its last item ended, GAME_CHUNK_PLIES half-moves at a time; once it is over (or at the
 * start), another game: the one chosen (played again from its start), else one not played yet in
 * the run, else any.
 */
final readonly class GamePicker
{
    public const KEY_PREFIX = 'game:';

    private Randomizer $randomizer;

    public function __construct(
        private LegendaryGames $games,
        private AttemptRepository $attempts,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer();
    }

    /**
     * @return Exercise|null null when there is no game (or not the one chosen)
     */
    public function pick(Run $run, ?int $gameId): ?Exercise
    {
        $last = $this->attempts->lastGameOfRun($run);
        if (null !== $last) {
            $game = $this->games->find((int) substr((string) $last->getLineKey(), \strlen(self::KEY_PREFIX)));
            $from = \count($last->getShown()) + \count($last->getExpected());
            if (null !== $game && $from < \count($game->moves) && (null === $gameId || $gameId === $game->id)) {
                return $this->chunk($game, $from);
            }
        }
        if (null !== $gameId) {
            $game = $this->games->find($gameId);

            return null === $game ? null : $this->chunk($game, 0);
        }
        $all = array_values($this->games->all());
        if ([] === $all) {
            return null;
        }
        $played = $this->attempts->lineKeysOfRun($run);
        $fresh = array_values(array_filter($all, static fn (LegendaryGame $game): bool => !\in_array(self::KEY_PREFIX.$game->id, $played, true)));
        $pool = [] === $fresh ? $all : $fresh;

        return $this->chunk($pool[$this->randomizer->getInt(0, \count($pool) - 1)], 0);
    }

    /**
     * The game from its half-move $from: the next chunk to play or write.
     */
    private function chunk(LegendaryGame $game, int $from): ?Exercise
    {
        $rules = Rules::initial();
        foreach (\array_slice($game->moves, 0, $from) as $uci) {
            if (null === $rules->playUci($uci)) {
                return null;
            }
        }

        return new Exercise(
            null,
            $rules->fen(),
            \array_slice($game->moves, $from, NotationRules::GAME_CHUNK_PLIES),
            \array_slice($game->moves, 0, $from),
            self::KEY_PREFIX.$game->id,
            $game->label(),
            'white',
        );
    }
}
