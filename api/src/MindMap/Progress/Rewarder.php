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
use App\Shop\Wallet\Wallets;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What a completed step earns (docs/MIND_MAP.md, § 6), inside the caller's transaction and under
 * the player's path lock: its crystals (a chest to open, credited now, outside the sessions' daily
 * cap: each is earned once), its XP bonus (an event, Gamification awards it), then the chests on
 * the way this completion earned.
 */
final readonly class Rewarder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Wallets $wallets,
        private EventPublisher $events,
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
        if ($step->crystals > 0) {
            $chest = new Chest($user, $capacity, $step->key, $step->crystals, $now);
            $this->entityManager->persist($chest);
            $balance = $this->wallets->lock($user->getId(), $now);
            $this->wallets->move($user->getId(), $balance, $step->crystals, LedgerKind::Reward, ['reason' => mb_substr(self::reason($capacity, $step), 0, 255)], $now);
        }
        $this->entityManager->flush();
        $this->events->publish(new StepCompleted(
            userId: $user->getId()->toRfc4122(),
            progressId: $row->getId()->toRfc4122(),
            capacity: $capacity->value,
            step: $step->key,
            kind: $step->kind->value,
            xp: $step->xp,
            crystals: $step->crystals,
            runId: $runId ?? '',
            occurredAt: $now,
        ));

        return $chest;
    }

    /** The ledger's line: « Carte mentale · visualisation · 2-1-boss ». */
    private static function reason(Capacity $capacity, Step $step): string
    {
        return \sprintf('Carte mentale · %s · %s', $capacity->value, $step->key);
    }
}
