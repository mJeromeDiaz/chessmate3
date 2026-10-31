<?php

declare(strict_types=1);

namespace App\Arena\Game;

use App\Activity\Event\ExerciseCompleted;
use App\Activity\EventPublisher;
use App\Arena\ArenaRules;
use App\Arena\Roster\Availability;
use App\Arena\Roster\Roster;
use App\Entity\Arena\Game;
use App\Entity\User;
use App\Enum\Activity\ExerciseType;
use App\Enum\Arena\BossStatus;
use App\Enum\Arena\GameEnd;
use App\Enum\Arena\GameStatus;
use App\Enum\Repertoire\Color;
use App\Enum\Shop\LedgerKind;
use App\Gamification\Xp\XpLedger;
use App\Repository\Arena\GameRepository;
use App\Shop\Wallet\Wallets;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The games of the arena (docs/ARENA.md, § 3 and 4): started against a boss the player can
 * challenge, one at a time; the moves of both sides sent by the client (the boss's are computed in
 * the browser), checked legal and timed by the server; ended by the rules, a resignation, the
 * player's clock or an abandonment, or cancelled at any time (counted nowhere). Each change runs in
 * a transaction under the player's lock; a refusal is returned, never thrown (the entity manager
 * stays open). A finished game is an exercise (its XP within the daily cap) and the boss's first
 * defeat pays its crystals.
 */
final readonly class Games
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private GameRepository $games,
        private Availability $availability,
        private Wallets $wallets,
        private EventPublisher $events,
        private XpLedger $xpLedger,
    ) {
    }

    /**
     * @param string $color white, black or random
     */
    public function start(User $user, string $boss, string $color, string $cadence, \DateTimeImmutable $now): Game|GameRefusal
    {
        $settings = Roster::find($boss);
        $control = ArenaRules::cadence($cadence);
        if (null === $settings || null === $control) {
            return GameRefusal::UnknownBoss;
        }
        if (BossStatus::Available !== ($this->availability->of($user)[$boss] ?? null)) {
            return GameRefusal::BossLocked;
        }
        $side = Color::tryFrom($color) ?? (0 === random_int(0, 1) ? Color::White : Color::Black);

        return $this->entityManager->wrapInTransaction(function () use ($user, $settings, $side, $cadence, $control, $now): Game|GameRefusal {
            $this->games->lockArenaOf($user);
            $active = $this->games->findActive($user);
            if (null !== $active && !$this->expire($active, $now)) {
                return GameRefusal::GameInProgress;
            }
            $game = new Game($user, $settings->character, $side, $settings->elo, $cadence, $control, $now);
            $this->entityManager->persist($game);
            $this->entityManager->flush();

            return $game;
        });
    }

    /** The player's game in progress, closed first when it ended by itself. */
    public function current(User $user, \DateTimeImmutable $now): ?Game
    {
        $game = $this->games->findActive($user);
        if (null === $game || null === $game->expiry($now)) {
            return $game;
        }
        $this->entityManager->wrapInTransaction(function () use ($game, $now): void {
            $this->games->lockArenaOf($game->getUser());
            $this->entityManager->refresh($game);
            $this->expire($game, $now);
        });

        return null;
    }

    public function find(User $user, Uuid $id, \DateTimeImmutable $now): ?Game
    {
        $game = $this->games->findOwned($id, $user);
        if (null !== $game && null !== $game->expiry($now)) {
            $this->entityManager->wrapInTransaction(function () use ($game, $now): void {
                $this->games->lockArenaOf($game->getUser());
                $this->entityManager->refresh($game);
                $this->expire($game, $now);
            });
        }

        return $game;
    }

    /**
     * A move of the side to move, the $ply-th of the game (0 for the first). The same move sent
     * again (a retry) answers the game as it stands.
     */
    public function move(User $user, Uuid $id, int $ply, string $uci, \DateTimeImmutable $now): Game|GameRefusal
    {
        return $this->locked($user, $id, $now, function (Game $game) use ($ply, $uci, $now): Game|GameRefusal {
            $moves = $game->getMoves();
            if ($ply === \count($moves) - 1 && $moves[$ply] === $uci) {
                return $game;
            }
            if (!$game->isActive()) {
                return GameRefusal::Over;
            }
            if ($ply !== \count($moves) || $ply >= ArenaRules::MAX_PLIES) {
                return GameRefusal::OutOfSync;
            }
            $judged = Referee::judge([...$moves, $uci]);
            if (!$judged['legal']) {
                return GameRefusal::Illegal;
            }
            $byPlayer = $game->isPlayerTurn();
            if (!$game->play($uci, $now)) {
                $this->finish($game, GameStatus::Lost, GameEnd::Timeout, $now);

                return $game;
            }
            $end = $judged['end'];
            if (null !== $end) {
                $status = GameEnd::Checkmate === $end ? ($byPlayer ? GameStatus::Won : GameStatus::Lost) : GameStatus::Drawn;
                $this->finish($game, $status, $end, $now);
            }

            return $game;
        });
    }

    public function resign(User $user, Uuid $id, \DateTimeImmutable $now): Game|GameRefusal
    {
        return $this->locked($user, $id, $now, function (Game $game) use ($now): Game|GameRefusal {
            if (!$game->isActive()) {
                return GameRefusal::Over;
            }
            $this->finish($game, GameStatus::Lost, GameEnd::Resignation, $now);

            return $game;
        });
    }

    /** Cancels the game, at any time: no result, no XP, counted nowhere. */
    public function abort(User $user, Uuid $id, \DateTimeImmutable $now): Game|GameRefusal
    {
        return $this->locked($user, $id, $now, function (Game $game) use ($now): Game|GameRefusal {
            if (!$game->isActive()) {
                return GameRefusal::Over;
            }
            $game->finish(GameStatus::Aborted, GameEnd::Aborted, $now);

            return $game;
        });
    }

    /**
     * « Recommencer »: cancels the game in progress (a finished one stays as it is) and starts
     * another against the same boss, same side and time control.
     */
    public function restart(User $user, Uuid $id, \DateTimeImmutable $now): Game|GameRefusal
    {
        $game = $this->games->findOwned($id, $user);
        if (null === $game) {
            return GameRefusal::NotFound;
        }
        if ($game->isActive()) {
            $aborted = $this->abort($user, $id, $now);
            if ($aborted instanceof GameRefusal) {
                return $aborted;
            }
        }

        return $this->start($user, $game->getBoss(), $game->getColor()->value, $game->getCadence(), $now);
    }

    /**
     * Runs $change on the player's game under the player's lock, once the game closed if it ended
     * by itself.
     *
     * @param callable(Game): (Game|GameRefusal) $change
     */
    private function locked(User $user, Uuid $id, \DateTimeImmutable $now, callable $change): Game|GameRefusal
    {
        return $this->entityManager->wrapInTransaction(function () use ($user, $id, $now, $change): Game|GameRefusal {
            $this->games->lockArenaOf($user);
            $game = $this->games->findOwned($id, $user);
            if (null === $game) {
                return GameRefusal::NotFound;
            }
            $this->entityManager->refresh($game);
            $this->expire($game, $now);
            $result = $change($game);
            $this->entityManager->flush();

            return $result;
        });
    }

    /**
     * Closes a game that ended by itself (the player's clock, an abandonment), inside a transaction.
     *
     * @return bool whether it was closed
     */
    private function expire(Game $game, \DateTimeImmutable $now): bool
    {
        $end = $game->expiry($now);
        if (null === $end) {
            return false;
        }
        $this->finish($game, GameStatus::Lost, $end, $now);
        $this->entityManager->flush();

        return true;
    }

    /**
     * Ends the game: its exercise (its XP within the daily cap), and the boss's crystals the first
     * time it is beaten.
     */
    private function finish(Game $game, GameStatus $status, GameEnd $end, \DateTimeImmutable $now): void
    {
        $game->finish($status, $end, $now);
        $user = $game->getUser();
        $crystals = 0;
        $boss = Roster::find($game->getBoss());
        if (GameStatus::Won === $status && null !== $boss && !$this->games->isPaid($user, $boss->character)) {
            $crystals = $boss->crystals();
            // A guest's are only shown, never credited, even once promoted (docs/GUEST.md).
            if (!$user->isGuest()) {
                $balance = $this->wallets->lock($user->getId(), $now);
                $this->wallets->move($user->getId(), $balance, $crystals, LedgerKind::Reward, ['reason' => \sprintf('Arène · %s', $boss->character)], $now);
            }
        }
        $event = new ExerciseCompleted(
            userId: $user->getId()->toRfc4122(),
            type: ExerciseType::ArenaGame,
            success: GameStatus::Won === $status,
            durationMs: max(0, Game::ms($now) - Game::ms($game->getStartedAt())),
            itemCount: 1,
            sourceType: 'arena_game',
            sourceId: $game->getId()->toRfc4122(),
            occurredAt: $now,
            metadata: [
                'boss' => $game->getBoss(),
                'result' => $status->value,
                'end' => $end->value,
                'plies' => $game->ply(),
                'elo' => $game->getElo(),
                'color' => $game->getColor()->value,
                'cadence' => $game->getCadence(),
            ],
        );
        $game->reward($crystals, $this->xpLedger->preview($event));
        $this->events->publish($event);
    }
}
