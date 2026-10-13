<?php

declare(strict_types=1);

namespace App\State\PuzzleStreak;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PuzzleStreak\Openings;
use App\PuzzleStreak\Opening\OpeningChoices;
use App\Security\AuthenticatedUser;

/**
 * GET /puzzle-streak/openings: the current user's own openings, and every family.
 *
 * @implements ProviderInterface<Openings>
 */
final class OpeningsProvider implements ProviderInterface
{
    public function __construct(
        private readonly OpeningChoices $choices,
        private readonly AuthenticatedUser $authenticatedUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Openings
    {
        $choices = $this->choices->of($this->authenticatedUser->get());
        $view = new Openings();
        $view->mine = $choices['mine'];
        $view->all = $choices['all'];

        return $view;
    }
}
