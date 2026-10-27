<?php

declare(strict_types=1);

namespace App\Arena\Roster;

use App\Entity\User;
use App\Enum\Arena\BossStatus;
use App\Enum\MindMap\Capacity;
use App\Enum\MindMap\StepStatus;
use App\MindMap\Path\Challenges;
use App\MindMap\Path\PathState;
use App\MindMap\Path\Step;
use App\MindMap\Progress\PathCatalog;
use App\MindMap\Progress\Progresses;
use App\Repository\MindMap\ProgressRepository;

/**
 * Which bosses the player can challenge (docs/ARENA.md, § 1): a boss of the visualisation or the
 * calculation path once it is open on its path (one of its steps), a boss of the endgames or the
 * défis (where nothing is locked) once beaten there; the défis' opening boss once one of the
 * player's opening bosses is beaten. A boss whose fights are not built yet: soon.
 */
final readonly class Availability
{
    public function __construct(
        private ProgressRepository $progress,
        private PathCatalog $catalog,
    ) {
    }

    /**
     * @return array<string, BossStatus> by character, in the roster's order
     */
    public function of(User $user): array
    {
        /** @var array<string, array<string, StepStatus>> $statuses by capacity, then step key */
        $statuses = [];
        /** @var array<string, list<string>> $beaten steps completed, by capacity */
        $beaten = [];
        $result = [];
        foreach (Roster::all() as $boss) {
            $capacity = $boss->capacity;
            if (self::opensWhenMet($capacity)) {
                $statuses[$capacity->value] ??= $this->statuses($user, $capacity);
            } else {
                $beaten[$capacity->value] ??= $this->completed($user, $capacity);
            }
            $result[$boss->character] = $this->status($boss, $statuses[$capacity->value] ?? [], $beaten[$capacity->value] ?? []);
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

    /** The visualisation and the calculation lock their chapters: their bosses count once met. */
    public static function opensWhenMet(Capacity $capacity): bool
    {
        return \in_array($capacity, [Capacity::Visualisation, Capacity::Calculation], true);
    }

    /**
     * @param array<string, StepStatus> $statuses
     * @param list<string>              $completed
     */
    private function status(Boss $boss, array $statuses, array $completed): BossStatus
    {
        if (Roster::OPENING_BOSS === $boss->character) {
            foreach ($completed as $key) {
                if (str_ends_with($key, '-boss') && null !== Challenges::parseOpening($key)) {
                    return BossStatus::Available;
                }
            }

            return BossStatus::Locked;
        }
        $steps = Roster::steps($boss);
        $playable = array_filter($steps, static fn (Step $step): bool => $step->isPlayable());
        if ([] === $playable) {
            return BossStatus::Soon;
        }
        foreach ($playable as $step) {
            $met = self::opensWhenMet($boss->capacity)
                ? \in_array($statuses[$step->key] ?? StepStatus::Locked, [StepStatus::Open, StepStatus::Done], true)
                : \in_array($step->key, $completed, true);
            if ($met) {
                return BossStatus::Available;
            }
        }

        return BossStatus::Locked;
    }

    /**
     * @return array<string, StepStatus>
     */
    private function statuses(User $user, Capacity $capacity): array
    {
        return PathState::statuses($this->catalog->chapters($user, $capacity), Progresses::state($this->progress->byStep($user, $capacity)));
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
