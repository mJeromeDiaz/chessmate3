<?php

declare(strict_types=1);

namespace App\Woodpecker\Set;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A rule checked before creating a set, e.g. the free plan's limit on the number or size of sets
 * (Phase 7). None registered yet: implementing the interface is enough (autoconfigured tag).
 */
#[AutoconfigureTag(self::TAG)]
interface CreationPolicyInterface
{
    public const TAG = 'app.woodpecker.creation_policy';

    /**
     * @throws HttpExceptionInterface when the user may not create this set
     */
    public function check(User $user, SetConfig|LightConfig $config): void;
}
