<?php

declare(strict_types=1);

namespace App\State\Repertoire;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Repertoire\Repertoire;
use App\Repository\Repertoire\RepertoireRepository;
use App\Repository\Repertoire\SegmentRepository;
use App\Security\AuthenticatedUser;
use Symfony\Component\Uid\Uuid;

/**
 * GET /repertoires (newest first) and /repertoires/{id}: the user's repertoires only.
 *
 * @implements ProviderInterface<Repertoire>
 */
final class RepertoireProvider implements ProviderInterface
{
    public function __construct(
        private readonly RepertoireRepository $repertoires,
        private readonly SegmentRepository $segments,
        private readonly AuthenticatedUser $authenticatedUser,
    ) {
    }

    /**
     * @return Repertoire|list<Repertoire>|null
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Repertoire|array|null
    {
        $user = $this->authenticatedUser->get();
        $counts = $this->segments->countPresentableByRepertoire($user);

        if ($operation instanceof CollectionOperationInterface) {
            return array_map(
                static fn ($repertoire): Repertoire => Repertoire::from($repertoire, $counts[$repertoire->getId()->toRfc4122()] ?? 0),
                $this->repertoires->findByUser($user),
            );
        }

        $id = RepertoireIds::fromUri($uriVariables, 'id');
        $repertoire = null === $id ? null : $this->repertoires->findOwned($id, $user);

        return null === $repertoire ? null : Repertoire::from($repertoire, $counts[$repertoire->getId()->toRfc4122()] ?? 0);
    }
}
