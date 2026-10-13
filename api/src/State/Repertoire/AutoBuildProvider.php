<?php

declare(strict_types=1);

namespace App\State\Repertoire;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Repertoire\AutoBuild;
use App\Repertoire\Auto\AutoBuildService;
use App\Security\AuthenticatedUser;

/**
 * GET /repertoires/auto-builds/{id}: the user's build, with the synthesis once ready.
 *
 * @implements ProviderInterface<AutoBuild>
 */
final class AutoBuildProvider implements ProviderInterface
{
    public function __construct(
        private readonly AutoBuildService $builds,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly AutoBuildViews $views,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?AutoBuild
    {
        $id = RepertoireIds::fromUri($uriVariables, 'id');
        $build = null === $id ? null : $this->builds->get($this->authenticatedUser->get(), $id);

        return null === $build ? null : $this->views->view($build);
    }
}
