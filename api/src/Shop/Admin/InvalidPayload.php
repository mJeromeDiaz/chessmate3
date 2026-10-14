<?php

declare(strict_types=1);

namespace App\Shop\Admin;

/**
 * An admin's form refused (422), field by field.
 */
final class InvalidPayload extends \RuntimeException
{
    /**
     * @param list<array{propertyPath: string, title: string}> $violations
     */
    public function __construct(public readonly array $violations)
    {
        parent::__construct('Invalid payload.');
    }

    public static function field(string $path, string $title): self
    {
        return new self([['propertyPath' => $path, 'title' => $title]]);
    }
}
