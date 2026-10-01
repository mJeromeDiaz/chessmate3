<?php

declare(strict_types=1);

namespace App\State\Repertoire;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Repertoire\Overview;
use App\ApiResource\Repertoire\RunReport;
use App\ApiResource\Repertoire\SegmentHistory;
use App\ApiResource\Repertoire\Stats;
use App\Repertoire\Stats\StatsReader;
use App\Security\AuthenticatedUser;

/**
 * GET /repertoires/stats, /repertoires/{id}/stats, /repertoires/{repertoireId}/segments/{id} and
 * /repertoires/runs/{id}: the user's data only (404 otherwise).
 *
 * @implements ProviderInterface<Overview|Stats|SegmentHistory|RunReport>
 */
final class StatsProvider implements ProviderInterface
{
    public function __construct(
        private readonly StatsReader $reader,
        private readonly AuthenticatedUser $authenticatedUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Overview|Stats|SegmentHistory|RunReport|null
    {
        $user = $this->authenticatedUser->get();
        $id = RepertoireIds::fromUri($uriVariables, 'id');

        return match ($operation->getClass()) {
            Overview::class => $this->reader->overview($user),
            Stats::class => null === $id ? null : $this->reader->repertoire($user, $id),
            SegmentHistory::class => null === $id || null === ($repertoireId = RepertoireIds::fromUri($uriVariables, 'repertoireId')) ? null : $this->reader->segment($user, $repertoireId, $id),
            RunReport::class => null === $id ? null : $this->reader->run($user, $id),
            default => throw new \LogicException('Unexpected resource.'),
        };
    }
}
