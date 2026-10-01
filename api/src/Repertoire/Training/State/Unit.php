<?php

declare(strict_types=1);

namespace App\Repertoire\Training\State;

use App\Enum\Repertoire\TestUnit;

/**
 * The unit being played in a run: its questions as they were when it started (the repertoire may
 * change meanwhile) and the presentations it logs, one per segment.
 */
final class Unit
{
    public int $cursor = 0;

    /**
     * @param list<array{uci: string, san: string}>                                   $context   played without asking, from the initial position
     * @param array{opening: array{eco: string, name: string}|null, move: string|null} $label
     * @param list<Question>                                                          $questions
     * @param list<array{segmentId: string, presentationId: string}>                 $segments
     */
    public function __construct(
        public readonly string $id,
        public readonly string $repertoireId,
        public readonly string $key,
        public readonly TestUnit $unit,
        public readonly int $rank,
        public readonly int $round,
        public readonly bool $retry,
        public readonly bool $newRound,
        public readonly string $startedAt,
        public readonly string $orientation,
        public readonly array $context,
        public readonly bool $deviation,
        public readonly array $label,
        public readonly array $questions,
        public readonly array $segments,
    ) {
    }

    public function current(): ?Question
    {
        return $this->questions[$this->cursor] ?? null;
    }

    public function isDone(): bool
    {
        return $this->cursor >= \count($this->questions);
    }

    public function errors(): int
    {
        return \count(array_filter($this->questions, static fn (Question $question): bool => false === $question->correct));
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $label = Read::array($data, 'label');
        $opening = null === ($label['opening'] ?? null) ? null : Read::array($label, 'opening');
        $unit = new self(
            Read::string($data, 'id'),
            Read::string($data, 'repertoireId'),
            Read::string($data, 'key'),
            TestUnit::from(Read::string($data, 'unit')),
            Read::int($data, 'rank'),
            Read::int($data, 'round'),
            Read::bool($data, 'retry'),
            Read::bool($data, 'newRound'),
            Read::string($data, 'startedAt'),
            Read::string($data, 'orientation'),
            Read::moves($data, 'context'),
            Read::bool($data, 'deviation'),
            [
                'opening' => null === $opening ? null : ['eco' => Read::string($opening, 'eco'), 'name' => Read::string($opening, 'name')],
                'move' => Read::nullableString($label, 'move'),
            ],
            array_map(Question::fromArray(...), Read::arrays($data, 'questions')),
            array_map(static fn (array $segment): array => ['segmentId' => Read::string($segment, 'segmentId'), 'presentationId' => Read::string($segment, 'presentationId')], Read::arrays($data, 'segments')),
        );
        $unit->cursor = Read::int($data, 'cursor');

        return $unit;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'repertoireId' => $this->repertoireId,
            'key' => $this->key,
            'unit' => $this->unit->value,
            'rank' => $this->rank,
            'round' => $this->round,
            'retry' => $this->retry,
            'newRound' => $this->newRound,
            'startedAt' => $this->startedAt,
            'orientation' => $this->orientation,
            'context' => $this->context,
            'deviation' => $this->deviation,
            'label' => $this->label,
            'questions' => array_map(static fn (Question $question): array => $question->toArray(), $this->questions),
            'segments' => $this->segments,
            'cursor' => $this->cursor,
        ];
    }
}
