<?php

declare(strict_types=1);

namespace App\Repertoire\Training\State;

/**
 * Typed reads of the run state stored as JSON.
 */
final class Read
{
    /**
     * @param array<mixed> $data
     */
    public static function string(array $data, string $key): string
    {
        return \is_string($data[$key] ?? null) ? $data[$key] : throw new \UnexpectedValueException('No string '.$key);
    }

    /**
     * @param array<mixed> $data
     */
    public static function nullableString(array $data, string $key): ?string
    {
        return null === ($data[$key] ?? null) ? null : self::string($data, $key);
    }

    /**
     * @param array<mixed> $data
     */
    public static function int(array $data, string $key): int
    {
        return \is_int($data[$key] ?? null) ? $data[$key] : throw new \UnexpectedValueException('No integer '.$key);
    }

    /**
     * @param array<mixed> $data
     */
    public static function bool(array $data, string $key): bool
    {
        return \is_bool($data[$key] ?? null) ? $data[$key] : throw new \UnexpectedValueException('No boolean '.$key);
    }

    /**
     * @param array<mixed> $data
     */
    public static function nullableBool(array $data, string $key): ?bool
    {
        return null === ($data[$key] ?? null) ? null : self::bool($data, $key);
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    public static function array(array $data, string $key): array
    {
        return \is_array($data[$key] ?? null) ? $data[$key] : throw new \UnexpectedValueException('No array '.$key);
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<array<mixed>>
     */
    public static function arrays(array $data, string $key): array
    {
        $list = [];
        foreach (self::array($data, $key) as $item) {
            $list[] = \is_array($item) ? $item : throw new \UnexpectedValueException('No array in '.$key);
        }

        return $list;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, int>
     */
    public static function counts(array $data, string $key): array
    {
        $counts = [];
        foreach (self::array($data, $key) as $name => $count) {
            $counts[(string) $name] = \is_int($count) ? $count : throw new \UnexpectedValueException('No count in '.$key);
        }

        return $counts;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array{uci: string, san: string}
     */
    public static function move(array $data): array
    {
        return ['uci' => self::string($data, 'uci'), 'san' => self::string($data, 'san')];
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<array{uci: string, san: string}>
     */
    public static function moves(array $data, string $key): array
    {
        return array_map(self::move(...), self::arrays($data, $key));
    }

    public static function instant(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
    }

    public static function ms(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return max(0, (int) round(((float) $to->format('U.u') - (float) $from->format('U.u')) * 1000));
    }
}
