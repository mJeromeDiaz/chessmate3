<?php

declare(strict_types=1);

namespace App\State\EarlyAccess;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

trait InvitationIdTrait
{
    /**
     * @param array<string, mixed> $uriVariables
     */
    private static function invitationId(array $uriVariables): Uuid
    {
        $id = $uriVariables['id'] ?? null;
        if (!\is_string($id) || !Uuid::isValid($id)) {
            throw new NotFoundHttpException('Invitation not found.');
        }

        return Uuid::fromString($id);
    }
}
