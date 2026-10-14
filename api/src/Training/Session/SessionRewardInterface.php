<?php

declare(strict_types=1);

namespace App\Training\Session;

use App\Entity\Training\Run;
use App\Entity\Training\Session;

/**
 * What a completed session earns (docs/SHOP.md, § 4 bis): Training owns the hook, the store
 * implements it (config/services.yaml). Training never depends on the store.
 *
 * @phpstan-type RewardView array{id: string, crowns: int, paidMinutes: int, tier: string, capped: bool, opened: bool}
 */
interface SessionRewardInterface
{
    /**
     * Credits a session that just completed, in the transaction that closes it (idempotent).
     *
     * @param list<Run> $runs the session's runs
     */
    public function grant(Session $session, array $runs, \DateTimeImmutable $now): void;

    /**
     * The reward of each session that has one.
     *
     * @param list<Session> $sessions
     *
     * @return array<string, RewardView> by session id (RFC 4122)
     */
    public function viewsOf(array $sessions): array;
}
