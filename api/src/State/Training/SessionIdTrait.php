<?php

declare(strict_types=1);

namespace App\State\Training;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

trait SessionIdTrait
{
    /**
     * @param array<string, mixed> $uriVariables
     */
    private static function sessionId(array $uriVariables): Uuid
    {
        $id = $uriVariables['id'] ?? null;
        if (!\is_string($id) || !Uuid::isValid($id)) {
            throw new NotFoundHttpException('Session not found.');
        }

        return Uuid::fromString($id);
    }
}
