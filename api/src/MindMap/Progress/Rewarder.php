<?php

declare(strict_types=1);

namespace App\MindMap\Progress;

use App\Activity\EventPublisher;
use App\Entity\MindMap\Chest;
use App\Entity\MindMap\Progress;
use App\Entity\User;
use App\Enum\MindMap\Capacity;
use App\Enum\Shop\LedgerKind;
use App\MindMap\Event\StepCompleted;
use App\MindMap\Path\Paths;
use App\MindMap\Path\PathState;
use App\MindMap\Path\Step;
use App\Repository\MindMap\ChestRepository;
use App\Shop\Wallet\Wallets;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What a completed step earns (docs/MIND_MAP.md, § 6), inside the caller's transaction and under
 * the player's path lock: its crystals (a chest to open, credited now, outside the sessions' daily
 * cap: each is earned once, a step with a reward key once per key), its XP bonus (an event, Gamification awards it), then the chests on
 * the way this completion earned.
 */
final readonly class Rewarder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Wallets $wallets,
        private EventPublisher $events,
        private ChestRepository $chests,
    ) {
    }

    /**
     * @param array<string, Progress> $rows the player's progress on the path, by step key (completed with the new rows)
     *
     * @return Chest|null the step's own chest
     */
    public function completed(User $user, Capacity $capacity, Step $step, Progress $row, array &$rows, ?string $runId, \DateTimeImmutable $now): ?Chest
    {
        $chest = $this->reward($user, $capacity, $step, $row, $runId, $now);
        // The chests on the way: the whole path is scanned, a chest is due once.
        foreach (PathState::chestsDue(Paths::chapters($capacity), Progresses::state($rows)) as $due) {
            $dueRow = $rows[$due->key] ??= $this->create($user, $capacity, $due->key, $now);
            $dueRow->pass(1, $now);
            $this->reward($user, $capacity, $due, $dueRow, null, $now);
        }

        return $chest;
    }

    public function create(User $user, Capacity $capacity, string $step, \DateTimeImmutable $now): Progress
    {
        $row = new Progress($user, $capacity, $step, $now);
        $this->entityManager->persist($row);

        return $row;
    }

    private function reward(User $user, Capacity $capacity, Step $step, Progress $row, ?string $runId, \DateTimeImmutable $now): ?Chest
    {
        $chest = null;
        // Paid once per reward key: a step of the same key already paid pays nothing.
        $crystals = null !== $step->rewardKey && \in_array($step->rewardKey, $this->chests->paidRewardKeys($user, $capacity), true) ? 0 : $step->crystals;
        if ($crystals > 0) {
            $chest = new Chest($user, $capacity, $step->key, $crystals, $now, $step->rewardKey);
            $this->entityManager->persist($chest);
            if ($user->isGuest()) {
                // What a guest would have earned (docs/GUEST.md): the chest records it, opened at
                // once (nothing to open), and nothing is credited, even once promoted.
                $chest->open($now);
            } else {
                $balance = $this->wallets->lock($user->getId(), $now);
                $this->wallets->move($user->getId(), $balance, $crystals, LedgerKind::Reward, ['reason' => mb_substr(self::reason($capacity, $step), 0, 255)], $now);
            }
        }
        $this->entityManager->flush();
        $this->events->publish(new StepCompleted(
            userId: $user->getId()->toRfc4122(),
            progressId: $row->getId()->toRfc4122(),
            capacity: $capacity->value,
            step: $step->key,
            kind: $step->kind->value,
            xp: $step->xp,
            crystals: $crystals,
            runId: $runId ?? '',
            occurredAt: $now,
        ));

        return $chest;
    }

    /** The ledger's line: « Carte mentale · visualisation · 2-1-boss », « Finales · rooks-boss », « Défis · mates-boss ». */
    private static function reason(Capacity $capacity, Step $step): string
    {
        return match ($capacity) {
            Capacity::Endgames => \sprintf('Finales · %s', $step->key),
            Capacity::Challenges => \sprintf('Défis · %s', $step->key),
            default => \sprintf('Carte mentale · %s · %s', $capacity->value, $step->key),
        };
    }
}
