<?php

declare(strict_types=1);

namespace App\Puzzle\Attempt;

use App\Entity\Puzzle\Puzzle;
use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Lets another domain open an unrated replay of a puzzle the user met there (e.g. a stubborn
 * Woodpecker puzzle), besides the user's own puzzle history. Autoconfigured.
 */
#[AutoconfigureTag(self::TAG)]
interface ReplayAuthorizerInterface
{
    public const TAG = 'app.puzzle.replay_authorizer';

    public function canReplay(User $user, Puzzle $puzzle): bool;
}
