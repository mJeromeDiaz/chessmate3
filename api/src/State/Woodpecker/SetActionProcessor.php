<?php

declare(strict_types=1);

namespace App\State\Woodpecker;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Woodpecker\Set;
use App\Security\AuthenticatedUser;
use App\Woodpecker\Exception\SetNotFoundException;
use App\Woodpecker\Set\SetManager;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * POST /woodpecker/sets/{id}/{pause|resume|abandon|archive}. 409 when not allowed in the set's
 * current status.
 *
 * @implements ProcessorInterface<mixed, Set>
 */
final class SetActionProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly SetManager $manager,
        private readonly SetViewFactory $views,
        private readonly AuthenticatedUser $authenticatedUser,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Set
    {
        $user = $this->authenticatedUser->get();
        $id = $uriVariables['id'] ?? null;
        if (!\is_string($id) || !Uuid::isValid($id)) {
            throw new NotFoundHttpException();
        }
        $setId = Uuid::fromString($id);

        try {
            $set = match ($operation->getName()) {
                'woodpecker_set_pause' => $this->manager->pause($user, $setId),
                'woodpecker_set_resume' => $this->manager->resume($user, $setId),
                'woodpecker_set_abandon' => $this->manager->abandon($user, $setId),
                'woodpecker_set_archive' => $this->manager->archive($user, $setId),
                default => throw new \LogicException('Unknown set action.'),
            };
        } catch (SetNotFoundException) {
            throw new NotFoundHttpException('Set not found.');
        } catch (\DomainException $e) {
            throw new ConflictHttpException($e->getMessage());
        }

        return $this->views->create($set);
    }
}
