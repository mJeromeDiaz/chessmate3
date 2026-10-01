<?php

declare(strict_types=1);

namespace App\Repertoire\Training\State;

/**
 * One user move to find in a unit: one item of the run.
 */
final class Question
{
    public ?string $servedAt = null;
    public ?string $answeredAt = null;
    public ?bool $correct = null;

    /**
     * @param list<array{uci: string, san: string}> $play the opponent's moves played before asking
     */
    public function __construct(
        public readonly string $segmentId,
        public readonly string $positionId,
        public readonly string $fen,
        /** Ply of the position, on the canonical path. */
        public readonly int $ply,
        public readonly string $uci,
        public readonly string $san,
        public readonly ?string $comment,
        public readonly array $play,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $question = new self(
            Read::string($data, 'segmentId'),
            Read::string($data, 'positionId'),
            Read::string($data, 'fen'),
            Read::int($data, 'ply'),
            Read::string($data, 'uci'),
            Read::string($data, 'san'),
            Read::nullableString($data, 'comment'),
            Read::moves($data, 'play'),
        );
        $question->servedAt = Read::nullableString($data, 'servedAt');
        $question->answeredAt = Read::nullableString($data, 'answeredAt');
        $question->correct = Read::nullableBool($data, 'correct');

        return $question;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'segmentId' => $this->segmentId,
            'positionId' => $this->positionId,
            'fen' => $this->fen,
            'ply' => $this->ply,
            'uci' => $this->uci,
            'san' => $this->san,
            'comment' => $this->comment,
            'play' => $this->play,
            'servedAt' => $this->servedAt,
            'answeredAt' => $this->answeredAt,
            'correct' => $this->correct,
        ];
    }
}
