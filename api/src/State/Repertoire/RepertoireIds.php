<?php

declare(strict_types=1);

namespace App\State\Repertoire;

use Symfony\Component\Uid\Uuid;

/**
 * UUIDs from URI variables (the route requirements already restrict their shape).
 */
final class RepertoireIds
{
    /**
     * @param array<string, mixed> $uriVariables
     */
    public static function fromUri(array $uriVariables, string $name): ?Uuid
    {
        $value = $uriVariables[$name] ?? null;

        return \is_string($value) && Uuid::isValid($value) ? Uuid::fromString($value) : null;
    }
}
