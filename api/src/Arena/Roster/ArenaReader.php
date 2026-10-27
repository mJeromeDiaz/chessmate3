<?php

declare(strict_types=1);

namespace App\Arena\Roster;

use App\Arena\ArenaRules;
use App\Entity\User;
use App\Enum\Arena\BossStatus;
use App\Repository\Arena\GameRepository;

/**
 * The arena as the client shows it (docs/ARENA.md, § 4): every boss of the roster with its
 * settings, whether the player can challenge it and their results against it, and the badges.
 *
 * @phpstan-import-type Record from GameRepository
 *
 * @phpstan-type BossView array{character: string, capacity: string, tier: string, elo: int, endgameElo: int|null, styles: list<string>, guarded: bool, status: string, crystals: int, won: int, drawn: int, lost: int, firstWonAt: string|null}
 * @phpstan-type BadgeView array{badge: string, earnedAt: string}
 */
final readonly class ArenaReader
{
    public function __construct(
        private Availability $availability,
        private GameRepository $games,
    ) {
    }

    /**
     * @return array{statuses: array<string, BossStatus>, bosses: list<BossView>, badges: list<BadgeView>}
     */
    public function read(User $user): array
    {
        $statuses = $this->availability->of($user);
        $records = $this->games->records($user);
        $bosses = [];
        foreach (Roster::all() as $boss) {
            $bosses[] = self::view($boss, $statuses[$boss->character] ?? BossStatus::Locked, $records[$boss->character] ?? null);
        }

        return ['statuses' => $statuses, 'bosses' => $bosses, 'badges' => self::badges($records)];
    }

    /**
     * @param Record|null $record
     *
     * @return BossView
     */
    public static function view(Boss $boss, BossStatus $status, ?array $record): array
    {
        return [
            'character' => $boss->character,
            'capacity' => $boss->capacity->value,
            'tier' => $boss->tier->value,
            'elo' => $boss->elo,
            'endgameElo' => $boss->endgameElo,
            'styles' => $boss->styles,
            'guarded' => $boss->guarded,
            'status' => $status->value,
            // What its first defeat still pays.
            'crystals' => null !== $record && $record['paid'] ? 0 : ArenaRules::crystals($boss->tier),
            'won' => $record['won'] ?? 0,
            'drawn' => $record['drawn'] ?? 0,
            'lost' => $record['lost'] ?? 0,
            'firstWonAt' => null === $record ? null : $record['firstWonAt']?->format(\DATE_ATOM),
        ];
    }

    /**
     * The badges earned, when: the Nth boss beaten for the first time; every boss of the roster.
     *
     * @param array<string, Record> $records
     *
     * @return list<BadgeView>
     */
    public static function badges(array $records): array
    {
        $firstWins = [];
        foreach (Roster::all() as $boss) {
            $at = isset($records[$boss->character]) ? $records[$boss->character]['firstWonAt'] : null;
            if (null !== $at) {
                $firstWins[] = $at;
            }
        }
        sort($firstWins);
        $badges = [];
        foreach (ArenaRules::BADGES as [$badge, $count]) {
            if (\count($firstWins) >= $count) {
                $badges[] = ['badge' => $badge, 'earnedAt' => $firstWins[$count - 1]->format(\DATE_ATOM)];
            }
        }
        if ([] !== $firstWins && \count($firstWins) === \count(Roster::all())) {
            $badges[] = ['badge' => ArenaRules::BADGE_ALL, 'earnedAt' => $firstWins[\count($firstWins) - 1]->format(\DATE_ATOM)];
        }

        return $badges;
    }
}
