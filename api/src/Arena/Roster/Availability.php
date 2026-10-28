<?php

declare(strict_types=1);

namespace App\Arena\Roster;

use App\Arena\Master\Masters;
use App\Entity\User;
use App\Enum\Arena\BossStatus;
use App\Enum\Arena\Tier;
use App\Enum\MindMap\Capacity;
use App\MindMap\Path\Challenges;
use App\MindMap\Path\Step;
use App\Repository\Arena\UnlockRepository;
use App\Repository\MindMap\ProgressRepository;

/**
 * Which bosses the player can challenge (docs/ARENA.md, § 1): a boss of the paths once beaten on its
 * path (one of its steps completed), the défis' opening boss once one of the player's opening
 * bosses is beaten; the arena's own once bought, or from the start when it is free; a professor once
 * their feat is reached (§ 2 bis). A path's boss whose fights are not built yet: soon.
 *
 * @phpstan-import-type MasterProgress from Masters
 */
final readonly class Availability
{
    public function __construct(
        private ProgressRepository $progress,
        private UnlockRepository $unlocks,
        private Masters $masters,
    ) {
    }

    /**
     * @param array<string, MasterProgress>|null $masters the professors' feats, when already read
     *
     * @return array<string, BossStatus> by character, in the roster's order
     */
    public function of(User $user, ?array $masters = null): array
    {
        /** @var array<string, list<string>> $completed steps completed, by capacity */
        $completed = [];
        $purchased = null;
        $result = [];
        foreach (Roster::all() as $boss) {
            $capacity = $boss->capacity;
            if (Tier::Master === $boss->tier) {
                $masters ??= $this->masters->of($user);
                $result[$boss->character] = null !== ($masters[$boss->character]['reachedAt'] ?? null) ? BossStatus::Available : BossStatus::Locked;
                continue;
            }
            if (null === $capacity) {
                $purchased ??= $this->unlocks->purchased($user);
                $result[$boss->character] = !$boss->forSale || \in_array($boss->character, $purchased, true) ? BossStatus::Available : BossStatus::Shop;
                continue;
            }
            $completed[$capacity->value] ??= $this->completed($user, $capacity);
            $result[$boss->character] = self::status($boss, $completed[$capacity->value]);
        }

        return $result;
    }

    /**
     * @return list<string> the characters the player can challenge now
     */
    public function available(User $user): array
    {
        return array_keys(array_filter($this->of($user), static fn (BossStatus $status): bool => BossStatus::Available === $status));
    }

    /**
     * @param list<string> $completed
     */
    private static function status(Boss $boss, array $completed): BossStatus
    {
        if (Roster::OPENING_BOSS === $boss->character) {
            foreach ($completed as $key) {
                if (str_ends_with($key, '-boss') && null !== Challenges::parseOpening($key)) {
                    return BossStatus::Available;
                }
            }

            return BossStatus::Locked;
        }
        $playable = array_filter(Roster::steps($boss), static fn (Step $step): bool => $step->isPlayable());
        if ([] === $playable) {
            return BossStatus::Soon;
        }
        foreach ($playable as $step) {
            if (\in_array($step->key, $completed, true)) {
                return BossStatus::Available;
            }
        }

        return BossStatus::Locked;
    }

    /**
     * The keys of the steps completed on the path.
     *
     * @return list<string>
     */
    private function completed(User $user, Capacity $capacity): array
    {
        $keys = [];
        foreach ($this->progress->byStep($user, $capacity) as $key => $row) {
            if (null !== $row->getCompletedAt()) {
                $keys[] = (string) $key;
            }
        }

        return $keys;
    }
}
