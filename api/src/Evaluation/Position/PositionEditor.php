<?php

declare(strict_types=1);

namespace App\Evaluation\Position;

use App\ApiResource\Evaluation\PositionInput;
use App\Chess\InvalidPositionException;
use App\Chess\Rules;
use App\Entity\Evaluation\Position;
use App\Enum\Evaluation\Plan;
use App\Enum\Evaluation\PositionTag;
use App\Enum\Repertoire\Color;
use App\Repository\Evaluation\PositionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The positions to evaluate, as admins enter them (docs/EVALUATION.md): a legal FEN with a move to
 * play, normalized and unique; a position already played is deactivated, never deleted.
 */
final readonly class PositionEditor
{
    public function __construct(
        private PositionRepository $positions,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PositionRefusedException
     */
    public function create(PositionInput $input): Position
    {
        [$fen, $turn] = $this->checkFen($input->fen, null);
        $position = new Position($fen, $turn, $input->evalCp, self::plan($input), self::ideas($input), self::text($input->tip), self::tag($input), $input->rating, self::text($input->source), $this->clock->now());
        if (!$input->active) {
            $position->update($fen, $turn, $input->evalCp, self::plan($input), self::ideas($input), self::text($input->tip), self::tag($input), $input->rating, self::text($input->source), false, $this->clock->now());
        }
        $this->entityManager->persist($position);
        $this->entityManager->flush();

        return $position;
    }

    /**
     * @throws PositionRefusedException
     */
    public function update(Position $position, PositionInput $input): Position
    {
        [$fen, $turn] = $this->checkFen($input->fen, $position);
        $position->update($fen, $turn, $input->evalCp, self::plan($input), self::ideas($input), self::text($input->tip), self::tag($input), $input->rating, self::text($input->source), $input->active, $this->clock->now());
        $this->entityManager->flush();

        return $position;
    }

    /**
     * @throws PositionRefusedException a position already played
     */
    public function delete(Position $position): void
    {
        if (($this->positions->playCounts([$position])[$position->getId()->toRfc4122()] ?? 0) > 0) {
            throw new PositionRefusedException('played', 'This position was already played: deactivate it instead.');
        }
        $this->entityManager->remove($position);
        $this->entityManager->flush();
    }

    /**
     * The FEN normalized (move counters "0 1") and its side to move.
     *
     * @return array{string, Color}
     *
     * @throws PositionRefusedException
     */
    public static function normalize(string $fen): array
    {
        try {
            $rules = Rules::fromFen(trim($fen));
        } catch (InvalidPositionException $e) {
            throw new PositionRefusedException('illegal', 'Illegal FEN: '.$e->getMessage());
        }
        if ([] === $rules->legalMoves()) {
            throw new PositionRefusedException('no_move', 'The side to move has no legal move (mate or stalemate): nothing to evaluate.');
        }

        return [$rules->normalizedFen().' 0 1', 'w' === $rules->sideToMove() ? Color::White : Color::Black];
    }

    /**
     * @return array{string, Color}
     *
     * @throws PositionRefusedException
     */
    private function checkFen(string $fen, ?Position $self): array
    {
        [$normalized, $turn] = self::normalize($fen);
        $existing = $this->positions->findOneBy(['fen' => $normalized]);
        if (null !== $existing && $existing !== $self) {
            throw new PositionRefusedException('duplicate', 'This position is already in the catalogue.');
        }

        return [$normalized, $turn];
    }

    private static function plan(PositionInput $input): ?Plan
    {
        return null === $input->plan ? null : Plan::from($input->plan);
    }

    private static function tag(PositionInput $input): ?PositionTag
    {
        return null === $input->tag ? null : PositionTag::from($input->tag);
    }

    /**
     * @return list<string>
     */
    private static function ideas(PositionInput $input): array
    {
        return array_map(static fn (string $idea): string => trim($idea), $input->ideas);
    }

    private static function text(?string $text): ?string
    {
        return null === $text || '' === trim($text) ? null : trim($text);
    }
}
