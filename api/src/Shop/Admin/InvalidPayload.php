<?php

declare(strict_types=1);

namespace App\Shop\Admin;

/**
 * An admin's form refused (422), field by field.
 */
final class InvalidPayload extends \RuntimeException
{
    /**
     * @param list<array{propertyPath: string, message: string, parameters: array<string, int|string>}> $violations
     *        each message a key of the "admin" domain, translated when answered
     */
    public function __construct(public readonly array $violations)
    {
        parent::__construct('Invalid payload.');
    }
}
