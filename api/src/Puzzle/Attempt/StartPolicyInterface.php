<?php

declare(strict_types=1);

namespace App\Puzzle\Attempt;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A rule checked before handing out a puzzle, e.g. the free plan's daily limit (Phase 7). None is
 * registered yet: implement this interface and it is picked up automatically (autoconfigured tag).
 * Runs under the user's rating lock, so a counting policy sees a consistent count.
 */
#[AutoconfigureTag(self::TAG)]
interface StartPolicyInterface
{
    public const TAG = 'app.puzzle.start_policy';

    /**
     * @throws HttpExceptionInterface when the user may not start this attempt (e.g. 429 or 402)
     */
    public function check(User $user, bool $rated): void;
}
