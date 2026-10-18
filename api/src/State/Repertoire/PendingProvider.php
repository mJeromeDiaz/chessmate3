<?php

declare(strict_types=1);

namespace App\State\Repertoire;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Repertoire\PendingList;
use App\Entity\Repertoire\Pending;
use App\Enum\Repertoire\PendingStatus;
use App\Repertoire\Auto\PendingService;
use App\Repertoire\Exception\RepertoireNotFoundException;
use App\Security\AuthenticatedUser;
use Symfony\Component\Uid\Uuid;

/**
 * GET /repertoires/{id}/pending: the pending positions of one of the user's repertoires (404
 * otherwise), their status brought in line with the repertoire.
 *
 * @implements ProviderInterface<PendingList>
 */
final class PendingProvider implements ProviderInterface
{
    public function __construct(
        private readonly PendingService $pendings,
        private readonly AuthenticatedUser $authenticatedUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?PendingList
    {
        $id = RepertoireIds::fromUri($uriVariables, 'id');
        if (null === $id) {
            return null;
        }
        try {
            return self::view($id, $this->pendings->list($this->authenticatedUser->get(), $id));
        } catch (RepertoireNotFoundException) {
            return null;
        }
    }

    /**
     * @param list<Pending> $pendings
     */
    public static function view(Uuid $repertoireId, array $pendings): PendingList
    {
        $view = new PendingList();
        $view->id = $repertoireId->toRfc4122();
        foreach ($pendings as $pending) {
            if (PendingStatus::Open === $pending->getStatus()) {
                ++$view->open;
            }
            $view->items[] = [
                'id' => $pending->getId()->toRfc4122(),
                'fen' => $pending->getFen(),
                'path' => $pending->getPath(),
                'reason' => $pending->getReason()->value,
                'games' => $pending->getGames(),
                'share' => $pending->getShare(),
                'score' => $pending->getScore(),
                'status' => $pending->getStatus()->value,
                'answerUci' => $pending->getAnswerUci(),
            ];
        }

        return $view;
    }
}
