<?php

declare(strict_types=1);

namespace App\State\Woodpecker;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Woodpecker\Set;
use App\Repository\Woodpecker\SetRepository;
use App\Security\AuthenticatedUser;
use App\Woodpecker\Exception\SetNotFoundException;
use App\Woodpecker\Set\SetManager;
use Symfony\Component\Uid\Uuid;

/**
 * GET /woodpecker/sets and /woodpecker/sets/{id}: the user's sets only. Reading a set applies its
 * due transitions first (end of rest, lost run), so what is shown is current.
 *
 * @implements ProviderInterface<Set>
 */
final class SetProvider implements ProviderInterface
{
    public function __construct(
        private readonly SetRepository $sets,
        private readonly SetManager $manager,
        private readonly SetViewFactory $views,
        private readonly AuthenticatedUser $authenticatedUser,
    ) {
    }

    /**
     * @return Set|list<Set>|null
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Set|array|null
    {
        $user = $this->authenticatedUser->get();

        if ($operation instanceof CollectionOperationInterface) {
            $filters = \is_array($context['filters'] ?? null) ? $context['filters'] : [];
            $archived = \in_array($filters['archived'] ?? null, ['1', 'true'], true);
            $ongoing = $this->sets->findOngoing($user);
            if (null !== $ongoing) {
                $this->manager->load($user, $ongoing->getId());
            }

            return array_map($this->views->create(...), $this->sets->findByUser($user, $archived));
        }

        $id = $uriVariables['id'] ?? null;
        if (!\is_string($id) || !Uuid::isValid($id)) {
            return null;
        }
        try {
            return $this->views->create($this->manager->load($user, Uuid::fromString($id)));
        } catch (SetNotFoundException) {
            return null;
        }
    }
}
