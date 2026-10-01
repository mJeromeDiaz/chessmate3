<?php

declare(strict_types=1);

namespace App\Repertoire\Srs;

use App\Entity\User;
use App\Enum\Repertoire\CardState;
use App\Enum\Repertoire\MoveRole;
use App\Enum\Repertoire\Rating;
use App\Repertoire\Exception\NoPreparedMoveException;
use App\Repertoire\Exception\PositionNotFoundException;
use App\Repertoire\Exception\PreparedMoveChangedException;
use App\Repertoire\Transaction;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * Records an answer of the repertoire test (docs/REPERTOIRE.md): the move the user played in a
 * position where the repertoire prepares one. The card of (position, prepared move) is rated
 * ({@see Grader}), updated by FSRS when the rule says so, and the answer is always logged.
 */
final readonly class Reviewer
{
    public function __construct(
        private Connection $connection,
        private CardStore $cards,
        private Fsrs $fsrs,
        private Transaction $transaction,
    ) {
    }

    /**
     * @param Uuid|null   $runId    the timed run it is given in
     * @param string|null $askedUci the move the question was asked for, when it was prepared earlier
     *
     * @throws PositionNotFoundException    not a position of this user's repertoire
     * @throws NoPreparedMoveException      no move of the user prepared there
     * @throws PreparedMoveChangedException the prepared move is no longer $askedUci (nothing written)
     */
    public function answer(User $user, Uuid $repertoireId, Uuid $positionId, string $playedUci, int $thinkMs, \DateTimeImmutable $now, ?Uuid $runId = null, ?string $askedUci = null): Answer
    {
        $row = $this->connection->fetchAssociative(
            'SELECT p.fen, p.fen_hash, m.uci, m.san FROM repertoire_position p
             JOIN repertoire r ON r.id = p.repertoire_id
             LEFT JOIN repertoire_move m ON m.from_position_id = p.id AND m.role = ?
             WHERE p.id = ? AND p.repertoire_id = ? AND r.user_id = ?',
            [MoveRole::Reference->value, $positionId->toBinary(), $repertoireId->toBinary(), $user->getId()->toBinary()],
            [ParameterType::STRING, ParameterType::BINARY, ParameterType::BINARY, ParameterType::BINARY],
        );
        if (false === $row) {
            throw new PositionNotFoundException();
        }
        [$fen, $fenHash, $expectedUci, $expectedSan] = [$row['fen'], $row['fen_hash'], $row['uci'], $row['san']];
        if (!\is_string($fen) || !\is_string($fenHash) || !\is_string($expectedUci) || !\is_string($expectedSan)) {
            throw new NoPreparedMoveException();
        }
        if (null !== $askedUci && $askedUci !== $expectedUci) {
            throw new PreparedMoveChangedException();
        }
        $correct = $expectedUci === $playedUci;
        $thinkMs = max(0, $thinkMs);
        $rating = Grader::rate($correct, $thinkMs);

        return $this->transaction->run(function () use ($fen, $fenHash, $expectedUci, $expectedSan, $repertoireId, $playedUci, $thinkMs, $now, $runId, $correct, $rating): Answer {
            $stored = $this->cards->lock($repertoireId, $fenHash, $fen, $expectedUci, $now);
            $before = $stored->card;
            $updated = Grader::updatesCard($correct, $before, $now);
            if ($updated) {
                $lapse = Rating::Again === $rating && CardState::Review === $before->state;
                $stored = new StoredCard($stored->id, $this->fsrs->review($before, $rating, $now), $stored->reps + 1, $stored->lapses + ($lapse ? 1 : 0));
                $this->cards->save($stored);
            }
            $this->connection->executeStatement(
                'INSERT INTO repertoire_review (id, card_id, run_id, played_uci, correct, rating, think_ms, updated, card_before, card_after, reviewed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    Uuid::v7()->toBinary(), $stored->id->toBinary(), $runId?->toBinary(), $playedUci, $correct, $rating->value, $thinkMs, $updated,
                    json_encode($before->toArray(), \JSON_THROW_ON_ERROR), $updated ? json_encode($stored->card->toArray(), \JSON_THROW_ON_ERROR) : null,
                    $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                ],
                [
                    ParameterType::BINARY, ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING, ParameterType::BOOLEAN, ParameterType::INTEGER,
                    ParameterType::INTEGER, ParameterType::BOOLEAN, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING,
                ],
            );

            return new Answer($correct, $expectedUci, $expectedSan, $rating, $updated, $stored->id, $stored->card);
        });
    }
}
