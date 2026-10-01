<?php

declare(strict_types=1);

namespace App\State\Repertoire;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Repertoire\Graph;
use App\Repertoire\Graph\GraphReader;
use App\Repository\Repertoire\RepertoireRepository;
use App\Security\AuthenticatedUser;

/**
 * GET /repertoires/{id}/graph.
 *
 * @implements ProviderInterface<Graph>
 */
final class GraphProvider implements ProviderInterface
{
    public function __construct(
        private readonly RepertoireRepository $repertoires,
        private readonly GraphReader $reader,
        private readonly AuthenticatedUser $authenticatedUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?Graph
    {
        $id = RepertoireIds::fromUri($uriVariables, 'id');
        $repertoire = null === $id ? null : $this->repertoires->findOwned($id, $this->authenticatedUser->get());
        if (null === $repertoire) {
            return null;
        }

        $graph = new Graph();
        $graph->id = $repertoire->getId()->toRfc4122();
        $graph->name = $repertoire->getName();
        $graph->color = $repertoire->getColor()->value;
        $graph->version = $repertoire->getVersion();
        $graph->rootPositionId = $this->reader->rootId($repertoire);
        $graph->positions = $this->reader->positions($repertoire);
        $graph->moves = $this->reader->moves($repertoire);
        $graph->segments = $this->reader->segments($repertoire);

        return $graph;
    }
}
