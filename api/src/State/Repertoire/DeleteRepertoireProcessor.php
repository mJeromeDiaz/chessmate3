<?php

declare(strict_types=1);

namespace App\State\Repertoire;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Repertoire\Exception\RepertoireNotFoundException;
use App\Repertoire\RepertoireManager;
use App\Security\AuthenticatedUser;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * DELETE /repertoires/{id}: the repertoire and everything in it (the front asks for confirmation).
 *
 * @implements ProcessorInterface<mixed, null>
 */
final class DeleteRepertoireProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly RepertoireManager $manager,
        private readonly AuthenticatedUser $authenticatedUser,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $id = RepertoireIds::fromUri($uriVariables, 'id') ?? throw new NotFoundHttpException();
        try {
            $this->manager->delete($this->authenticatedUser->get(), $id);
        } catch (RepertoireNotFoundException) {
            throw new NotFoundHttpException('Repertoire not found.');
        }

        return null;
    }
}
