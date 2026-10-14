<?php

declare(strict_types=1);

namespace App\Shop\Admin;

/**
 * Reads an admin form's JSON fields, collecting what is wrong (docs/SHOP.md): every problem is
 * reported at once, then {@see done()} throws them.
 */
final class Payload
{
    /** @var list<array{propertyPath: string, title: string}> */
    private array $violations = [];

    /**
     * @param array<mixed> $data
     */
    public function __construct(private readonly array $data)
    {
    }

    public function string(string $field, int $max, bool $required = true): ?string
    {
        $value = $this->data[$field] ?? null;
        if (\is_string($value)) {
            $value = trim($value);
        }
        if (null === $value || '' === $value) {
            if ($required) {
                $this->fail($field, 'Ce champ est obligatoire.');
            }

            return null;
        }
        if (!\is_string($value)) {
            $this->fail($field, 'Un texte est attendu.');

            return null;
        }
        if (mb_strlen($value) > $max) {
            $this->fail($field, \sprintf('%d caractères au plus.', $max));
        }

        return $value;
    }

    public function int(string $field, int $min, int $max, bool $required = true): ?int
    {
        $value = $this->data[$field] ?? null;
        if (null === $value) {
            if ($required) {
                $this->fail($field, 'Ce champ est obligatoire.');
            }

            return null;
        }
        if (!\is_int($value) || $value < $min || $value > $max) {
            $this->fail($field, \sprintf('Un nombre entier de %d à %d est attendu.', $min, $max));

            return null;
        }

        return $value;
    }

    public function bool(string $field): bool
    {
        return true === ($this->data[$field] ?? false);
    }

    /** An instant (ISO 8601), in UTC; null when empty. */
    public function instant(string $field): ?\DateTimeImmutable
    {
        $value = $this->data[$field] ?? null;
        if (null === $value || '' === $value) {
            return null;
        }
        if (!\is_string($value)) {
            $this->fail($field, 'Une date est attendue.');

            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            $this->fail($field, 'Une date est attendue.');

            return null;
        }
    }

    /**
     * @return list<mixed>
     */
    public function list(string $field): array
    {
        $value = $this->data[$field] ?? [];
        if (!\is_array($value) || !array_is_list($value)) {
            $this->fail($field, 'Une liste est attendue.');

            return [];
        }

        return $value;
    }

    public function fail(string $field, string $title): void
    {
        $this->violations[] = ['propertyPath' => $field, 'title' => $title];
    }

    /**
     * @throws InvalidPayload when a field was wrong
     */
    public function done(): void
    {
        if ([] !== $this->violations) {
            throw new InvalidPayload($this->violations);
        }
    }
}
