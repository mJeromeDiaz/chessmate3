<?php

declare(strict_types=1);

namespace App\State\MindMap;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MindMap\Path;
use App\MindMap\Progress\PathReader;
use App\Security\AuthenticatedUser;
use App\Training\Run\TimeboxRunner;

/**
 * GET /mind-map/{capacity}: the player's path (a run of a step past its time is closed, hence
 * judged, first).
 *
 * @implements ProviderInterface<Path>
 */
final class PathProvider implements ProviderInterface
{
    use CapacityTrait;

    public function __construct(
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly PathReader $reader,
        private readonly TimeboxRunner $runner,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Path
    {
        $capacity = self::capacity($uriVariables);
        $user = $this->authenticatedUser->get();
        $this->runner->closeExpired($user);

        return Path::of($this->reader->read($user, $capacity));
    }
}
