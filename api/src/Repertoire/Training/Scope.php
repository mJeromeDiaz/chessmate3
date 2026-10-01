<?php

declare(strict_types=1);

namespace App\Repertoire\Training;

use App\Enum\Repertoire\TestUnit;
use App\Training\Exception\InvalidRunConfigException;
use Symfony\Component\Uid\Uuid;

/**
 * What a repertoire test covers: the run's config (docs/REPERTOIRE.md),
 * {repertoireIds: [...], unit?: segment|line, rootPositionId?, segmentIds?: [...]}.
 * rootPositionId (the editor's "Test this line") needs a single repertoire.
 */
final readonly class Scope
{
    public const MAX_REPERTOIRES = 50;
    public const MAX_SEGMENTS = 500;

    /**
     * @param list<string>             $repertoireIds RFC 4122
     * @param array<string, true>|null $segmentIds    RFC 4122
     */
    public function __construct(
        public array $repertoireIds,
        public TestUnit $unit,
        public ?string $rootPositionId = null,
        public ?array $segmentIds = null,
    ) {
    }

    /**
     * @param array<mixed> $config
     *
     * @throws InvalidRunConfigException
     */
    public static function fromConfig(array $config): self
    {
        $unknown = array_diff(array_keys($config), ['repertoireIds', 'unit', 'rootPositionId', 'segmentIds']);
        if ([] !== $unknown) {
            throw new InvalidRunConfigException(sprintf('Unknown option "%s".', implode('", "', $unknown)));
        }
        $repertoireIds = self::uuids($config['repertoireIds'] ?? null, 'repertoireIds', self::MAX_REPERTOIRES) ?? throw new InvalidRunConfigException('repertoireIds is required.');
        if ([] === $repertoireIds) {
            throw new InvalidRunConfigException('repertoireIds is empty.');
        }
        $unit = $config['unit'] ?? TestUnit::Segment->value;
        $unit = \is_string($unit) ? TestUnit::tryFrom($unit) : null;
        if (null === $unit) {
            throw new InvalidRunConfigException('unit is "segment" or "line".');
        }
        $root = $config['rootPositionId'] ?? null;
        if (null !== $root && (!\is_string($root) || !Uuid::isValid($root))) {
            throw new InvalidRunConfigException('rootPositionId is not a UUID.');
        }
        if (null !== $root && 1 !== \count($repertoireIds)) {
            throw new InvalidRunConfigException('rootPositionId needs a single repertoire.');
        }
        $segmentIds = self::uuids($config['segmentIds'] ?? null, 'segmentIds', self::MAX_SEGMENTS);

        return new self(
            array_values(array_unique($repertoireIds)),
            $unit,
            null === $root ? null : Uuid::fromString($root)->toRfc4122(),
            null === $segmentIds ? null : array_fill_keys($segmentIds, true),
        );
    }

    /**
     * @return array{repertoireIds: list<string>, unit: string, rootPositionId: string|null, segmentIds: list<string>|null}
     */
    public function toArray(): array
    {
        return [
            'repertoireIds' => $this->repertoireIds,
            'unit' => $this->unit->value,
            'rootPositionId' => $this->rootPositionId,
            'segmentIds' => null === $this->segmentIds ? null : array_keys($this->segmentIds),
        ];
    }

    /**
     * @return list<string>|null RFC 4122
     */
    private static function uuids(mixed $value, string $name, int $max): ?array
    {
        if (null === $value) {
            return null;
        }
        if (!\is_array($value) || !array_is_list($value) || \count($value) > $max) {
            throw new InvalidRunConfigException(sprintf('%s is a list of at most %d ids.', $name, $max));
        }
        $ids = [];
        foreach ($value as $id) {
            if (!\is_string($id) || !Uuid::isValid($id)) {
                throw new InvalidRunConfigException(sprintf('%s holds an invalid id.', $name));
            }
            $ids[] = Uuid::fromString($id)->toRfc4122();
        }

        return $ids;
    }
}
