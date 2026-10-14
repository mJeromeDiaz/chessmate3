<?php

declare(strict_types=1);

namespace App\Enum\Shop;

/**
 * Why a player's crowns moved (docs/SHOP.md). Stored as its value: add cases, never rename one.
 */
enum LedgerKind: string
{
    /** Spent on an offer. */
    case Purchase = 'purchase';
    /** Given by an admin. */
    case Grant = 'grant';
    /** Taken back by an admin. */
    case Revoke = 'revoke';
    /** Earned at the end of a completed training session (its chest). */
    case Reward = 'reward';
}
