<?php

declare(strict_types=1);

namespace App\State\Coach;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Coach\Diagnosis;
use App\Coach\Diagnosis\Diagnosis as DiagnosisRules;
use App\Repertoire\Auto\AutoBuildService;
use App\Repertoire\Auto\GameRecord;
use App\Security\AuthenticatedUser;
use App\State\Repertoire\RepertoireIds;

/**
 * GET /coach/builds/{id}/diagnosis.
 *
 * @implements ProviderInterface<Diagnosis>
 */
final class DiagnosisProvider implements ProviderInterface
{
    public function __construct(
        private readonly AutoBuildService $builds,
        private readonly AuthenticatedUser $authenticatedUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?Diagnosis
    {
        $id = RepertoireIds::fromUri($uriVariables, 'id');
        $build = null === $id ? null : $this->builds->get($this->authenticatedUser->get(), $id);
        if (null === $build) {
            return null;
        }
        $diagnosis = DiagnosisRules::of(GameRecord::listFromJson($build->getGames()));

        $view = new Diagnosis();
        $view->buildId = $build->getId()->toRfc4122();
        $view->status = $build->getStatus()->value;
        $view->games = $diagnosis['games'];
        $view->analysed = $diagnosis['analysed'];
        $view->rating = $diagnosis['rating'];
        $view->white = $diagnosis['white'];
        $view->black = $diagnosis['black'];
        $view->endgames = $diagnosis['endgames'];
        $view->weakest = $diagnosis['weakest'];

        return $view;
    }
}
