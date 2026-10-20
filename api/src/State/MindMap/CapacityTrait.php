<?php

declare(strict_types=1);

namespace App\State\MindMap;

use App\Enum\MindMap\Capacity;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

trait CapacityTrait
{
    /**
     * @param array<string, mixed> $uriVariables
     */
    private static function capacity(array $uriVariables): Capacity
    {
        $value = $uriVariables['capacity'] ?? null;

        return (\is_string($value) ? Capacity::tryFrom($value) : null) ?? throw new NotFoundHttpException('Capacity not found.');
    }
}
