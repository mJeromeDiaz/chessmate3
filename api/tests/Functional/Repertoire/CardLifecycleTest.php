<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repertoire;

use App\Chess\Rules;
use App\Entity\Repertoire\Repertoire;
use App\Enum\Repertoire\CardState;
use App\Enum\Repertoire\Color;
use App\Enum\Repertoire\Rating;
use App\Repertoire\Exception\NoPreparedMoveException;
use App\Repertoire\Exception\PositionNotFoundException;
use App\Repertoire\Import\ImportApplier;
use App\Repertoire\Import\PgnAnalyzer;
use App\Repertoire\Limits;
use App\Repertoire\RepertoireManager;
use App\Repertoire\Srs\Answer;
use App\Repertoire\Srs\CardStore;
use App\Repertoire\Srs\DueMove;
use App\Repertoire\Srs\DueQuery;
use App\Repertoire\Srs\Reviewer;
use App\Repertoire\Transaction;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Cards follow the prepared moves (docs/REPERTOIRE.md): keyed by (repertoire, position, expected
 * move), created at the first answer, never written when the repertoire changes; a move coming
 * back finds its memory, a new move starts afresh.
 */
final class CardLifecycleTest extends KernelTestCase
{
    use RepertoireTestTrait;

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->now = new \DateTimeImmutable('2026-10-01 10:00:00');
    }

    public function testAFirstAnswerCreatesTheCard(): void
    {
        $repertoire = $this->createRepertoire($this->createUser());
        $this->line($repertoire, 'e4 e5 Nf3');
        self::assertSame(['total' => 2, 'new' => 2, 'due' => 0], $this->counts($repertoire));
        self::assertSame(0, $this->cardCount($repertoire), 'no row before the first answer');

        $answer = $this->answer($repertoire, '', 'e4', 1500);

        self::assertTrue($answer->correct);
        self::assertSame(['e2e4', 'e4'], [$answer->expectedUci, $answer->expectedSan]);
        self::assertSame(Rating::Easy, $answer->rating);
        self::assertTrue($answer->updated);
        self::assertSame(CardState::Review, $answer->card->state);
        self::assertSame(1, $this->cardCount($repertoire));
        self::assertSame(['reps' => 1, 'lapses' => 0], $this->counters($answer->cardId));
        self::assertSame(['total' => 2, 'new' => 1, 'due' => 0], $this->counts($repertoire));
    }

    public function testARightAnswerOnACardNotDueIsOnlyLoggedAndAWrongOneAlwaysCounts(): void
    {
        $repertoire = $this->createRepertoire($this->createUser());
        $this->line($repertoire, 'e4 e5');
        $first = $this->answer($repertoire, '', 'e4', 1500);

        $again = $this->answer($repertoire, '', 'e4', 1500, $this->now->modify('+1 hour'));
        self::assertTrue($again->correct);
        self::assertFalse($again->updated, 'not due: the stability must not grow');
        self::assertEquals($first->card, $again->card);

        $wrong = $this->answer($repertoire, '', 'd4', 800, $this->now->modify('+2 hours'));
        self::assertFalse($wrong->correct);
        self::assertSame('e2e4', $wrong->expectedUci);
        self::assertSame(Rating::Again, $wrong->rating);
        self::assertTrue($wrong->updated);
        self::assertSame(CardState::Relearning, $wrong->card->state);
        self::assertSame(['reps' => 2, 'lapses' => 1], $this->counters($wrong->cardId));

        $log = $this->connection()->fetchAllAssociative(
            'SELECT played_uci, correct, rating, think_ms, updated, card_after IS NULL no_after FROM repertoire_review WHERE card_id = ? ORDER BY reviewed_at',
            [$first->cardId->toBinary()],
            [ParameterType::BINARY],
        );
        self::assertEquals([
            ['played_uci' => 'e2e4', 'correct' => 1, 'rating' => 4, 'think_ms' => 1500, 'updated' => 1, 'no_after' => 0],
            ['played_uci' => 'e2e4', 'correct' => 1, 'rating' => 4, 'think_ms' => 1500, 'updated' => 0, 'no_after' => 1],
            ['played_uci' => 'd2d4', 'correct' => 0, 'rating' => 1, 'think_ms' => 800, 'updated' => 1, 'no_after' => 0],
        ], $log);
    }

    public function testAReplacedMoveStartsAFreshCardAndUndoBringsTheOldMemoryBack(): void
    {
        $user = $this->createUser();
        $repertoire = $this->createRepertoire($user);
        $this->line($repertoire, 'e4 e5');
        $e4 = $this->answer($repertoire, '', 'e4', 1500);

        $this->editor()->replace($user, $repertoire->getId(), Uuid::fromString($this->moveId($repertoire, '', 'e4')), 'd2d4');
        self::assertSame(['total' => 1, 'new' => 1, 'due' => 0], $this->counts($repertoire), '1.d4 is new');
        $d4 = $this->answer($repertoire, '', 'd4', 3000);
        self::assertNotEquals($e4->cardId, $d4->cardId);
        self::assertSame(['reps' => 1, 'lapses' => 0], $this->counters($d4->cardId), 'counters start at zero');

        $this->editor()->undo($user, $repertoire->getId());
        self::assertSame(['total' => 1, 'new' => 0, 'due' => 0], $this->counts($repertoire), '1.e4 found its card');
        $back = $this->answer($repertoire, '', 'e4', 1500, $this->now->modify('+1 hour'));
        self::assertEquals($e4->cardId, $back->cardId);
        self::assertFalse($back->updated, 'its memory is intact: not due yet');
    }

    public function testASuiteDeletedThenRestoredFromTheTrashKeepsItsCards(): void
    {
        $user = $this->createUser();
        $repertoire = $this->createRepertoire($user);
        $this->line($repertoire, 'e4 e5 Nf3');
        $this->line($repertoire, 'e4 c5 Nf3');
        $nf3 = $this->answer($repertoire, 'e4 e5', 'Nf3', 1500);

        $this->editor()->delete($user, $repertoire->getId(), Uuid::fromString($this->moveId($repertoire, 'e4', 'e5')));
        self::assertSame(['total' => 2, 'new' => 2, 'due' => 0], $this->counts($repertoire), 'its card no longer counts');

        $trashId = $this->connection()->fetchOne('SELECT id FROM repertoire_trash WHERE repertoire_id = ?', [$repertoire->getId()->toBinary()], [ParameterType::BINARY]);
        self::assertIsString($trashId);
        $this->editor()->restore($user, $repertoire->getId(), Uuid::fromBinary($trashId)->toRfc4122());

        self::assertSame(['total' => 3, 'new' => 2, 'due' => 0], $this->counts($repertoire));
        self::assertEquals($nf3->cardId, $this->answer($repertoire, 'e4 e5', 'Nf3', 1500, $this->now->modify('+1 hour'))->cardId);
    }

    public function testTheSameMoveEnteredAgainAfterADefinitiveDeletionFindsItsMemory(): void
    {
        $user = $this->createUser();
        $repertoire = $this->createRepertoire($user);
        $this->line($repertoire, 'e4 e5 Nf3');
        $nf3 = $this->answer($repertoire, 'e4 e5', 'Nf3', 1500);

        $this->editor()->delete($user, $repertoire->getId(), Uuid::fromString($this->moveId($repertoire, 'e4 e5', 'Nf3')));
        $trashId = $this->connection()->fetchOne('SELECT id FROM repertoire_trash WHERE repertoire_id = ?', [$repertoire->getId()->toBinary()], [ParameterType::BINARY]);
        self::assertIsString($trashId);
        $this->editor()->discard($user, $repertoire->getId(), Uuid::fromBinary($trashId)->toRfc4122());
        self::assertSame(1, $this->cardCount($repertoire), 'kept, only no longer active');

        $this->line($repertoire, 'e4 e5 Nf3');
        self::assertSame(['total' => 2, 'new' => 1, 'due' => 0], $this->counts($repertoire));
        self::assertEquals($nf3->cardId, $this->answer($repertoire, 'e4 e5', 'Nf3', 1500, $this->now->modify('+1 hour'))->cardId);
    }

    public function testAnImportThatReplacesAMoveStartsAFreshCardAndItsUndoBringsTheOldOneBack(): void
    {
        $user = $this->createUser();
        $repertoire = $this->createRepertoire($user);
        $this->line($repertoire, 'e4 e5');
        $e4 = $this->answer($repertoire, '', 'e4', 1500);

        $tree = (new PgnAnalyzer(new Limits()))->analyze('1. d4 d5 2. c4 *');
        self::getContainer()->get(ImportApplier::class)->apply($user, $tree, ['repertoireId' => $repertoire->getId()], [Rules::initial()->normalizedFen() => 'd2d4']);
        self::assertSame(['total' => 2, 'new' => 2, 'due' => 0], $this->counts($repertoire));

        $this->editor()->undo($user, $repertoire->getId());
        self::assertSame(['total' => 1, 'new' => 0, 'due' => 0], $this->counts($repertoire));
        self::assertEquals($e4->cardId, $this->answer($repertoire, '', 'e4', 1500, $this->now->modify('+1 hour'))->cardId);
    }

    public function testATransposedPositionHasOneCard(): void
    {
        $repertoire = $this->createRepertoire($this->createUser());
        $this->line($repertoire, 'd4 Nf6 c4 e6 Nf3 d5 Nc3');
        $this->line($repertoire, 'd4 d5 c4 e6 Nf3 Nf6');

        // 1.d4, 2.c4 twice, 3.Nf3 twice, and 4.Nc3 once for the two paths.
        self::assertSame(['total' => 6, 'new' => 6, 'due' => 0], $this->counts($repertoire));
        $viaNf6 = $this->answer($repertoire, 'd4 Nf6 c4 e6 Nf3 d5', 'Nc3', 1500);
        $viaD5 = $this->answer($repertoire, 'd4 d5 c4 e6 Nf3 Nf6', 'Nc3', 1500, $this->now->modify('+1 minute'));
        self::assertEquals($viaNf6->cardId, $viaD5->cardId);
        self::assertSame(['total' => 6, 'new' => 5, 'due' => 0], $this->counts($repertoire));
    }

    public function testDueMovesAreTheNewAndTheOverdueOnesWithTheirSegment(): void
    {
        $repertoire = $this->createRepertoire($this->createUser());
        $this->line($repertoire, 'e4 e5 Nf3');
        $this->line($repertoire, 'e4 c5 Nf3');
        $e4 = $this->answer($repertoire, '', 'e4', 1500);

        $due = $this->dueQuery()->dueMoves([$repertoire->getId()], $this->now);
        $this->em()->clear(); // segments are written in SQL
        self::assertSame(['Nf3', 'Nf3'], array_map(fn (DueMove $move): string => $this->move($move->moveId->toRfc4122())->getSan(), $due), '1.e4 is not due');
        foreach ($due as $move) {
            self::assertNull($move->due);
            self::assertNotNull($move->segmentId);
            self::assertEquals($this->move($move->moveId->toRfc4122())->getSegment()?->getId(), $move->segmentId);
        }

        $later = $e4->card->due->modify('+1 second');
        $due = $this->dueQuery()->dueMoves([$repertoire->getId()], $later);
        self::assertCount(3, $due);
        self::assertEquals($e4->card->due, $due[0]->due, 'the overdue card first, then the new ones');
        self::assertSame(['total' => 3, 'new' => 2, 'due' => 1], $this->dueQuery()->counts([$repertoire->getId()], $later));
        self::assertSame([], $this->dueQuery()->dueMoves([], $later));
    }

    public function testAnswersAreRefusedOutsideTheUsersPreparedMoves(): void
    {
        $repertoire = $this->createRepertoire($this->createUser());
        $this->line($repertoire, 'e4 e5');

        try {
            $this->answer($repertoire, 'e4', 'e5', 1000);
            self::fail('The opponent\'s move is not the user\'s to answer.');
        } catch (NoPreparedMoveException) {
        }
        try {
            $this->answer($repertoire, 'e4 e5', 'Nf3', 1000);
            self::fail('Nothing prepared at the end of the line.');
        } catch (NoPreparedMoveException) {
        }

        $other = $this->createUser('bob@example.com');
        $this->expectException(PositionNotFoundException::class);
        $this->reviewer()->answer($other, $repertoire->getId(), $this->root($repertoire)->getId(), 'e2e4', 1000, $this->now);
    }

    public function testLockingCreatesTheRowOnceAndOnlyInATransaction(): void
    {
        $repertoire = $this->createRepertoire($this->createUser());
        $store = self::getContainer()->get(CardStore::class);
        $hash = random_bytes(16);

        [$first, $second] = self::getContainer()->get(Transaction::class)->run(fn (): array => [
            $store->lock($repertoire->getId(), $hash, 'fen', 'e2e4', $this->now),
            $store->lock($repertoire->getId(), $hash, 'fen', 'e2e4', $this->now->modify('+1 minute')),
        ]);
        self::assertEquals($first, $second, 'the second one reads the first one\'s row');
        self::assertEquals($first, $store->find($repertoire->getId(), $hash, 'e2e4'));
        self::assertNull($store->find($repertoire->getId(), $hash, 'd2d4'));

        $this->expectException(\LogicException::class);
        $store->lock($repertoire->getId(), $hash, 'fen', 'e2e4', $this->now);
    }

    public function testCardsAndAnswersGoWithTheirRepertoire(): void
    {
        $user = $this->createUser();
        $repertoire = $this->createRepertoire($user, Color::Black);
        $this->line($repertoire, 'e4 c5');
        $this->answer($repertoire, 'e4', 'c5', 1500);

        self::getContainer()->get(RepertoireManager::class)->delete($user, $repertoire->getId());

        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM repertoire_card'));
        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM repertoire_review'));
    }

    /** Plays $san after the SAN moves $path as an answer of the repertoire's owner. */
    private function answer(Repertoire $repertoire, string $path, string $san, int $thinkMs, ?\DateTimeImmutable $at = null): Answer
    {
        $rules = Rules::initial();
        foreach (array_filter(explode(' ', $path)) as $step) {
            $rules->playSan($step) ?? throw new \LogicException($step);
        }
        $fen = $rules->normalizedFen();
        $uci = Rules::uci($rules->playSan($san) ?? throw new \LogicException($san));
        $positionId = $this->connection()->fetchOne('SELECT id FROM repertoire_position WHERE repertoire_id = ? AND fen = ?', [$repertoire->getId()->toBinary(), $fen], [ParameterType::BINARY]);
        self::assertIsString($positionId);

        return $this->reviewer()->answer($repertoire->getUser(), $repertoire->getId(), Uuid::fromBinary($positionId), $uci, $thinkMs, $at ?? $this->now);
    }

    /**
     * @return array{total: int, new: int, due: int}
     */
    private function counts(Repertoire $repertoire): array
    {
        return $this->dueQuery()->counts([$repertoire->getId()], $this->now);
    }

    /**
     * @return array{reps: int, lapses: int}
     */
    private function counters(Uuid $cardId): array
    {
        $row = $this->connection()->fetchAssociative('SELECT reps, lapses FROM repertoire_card WHERE id = ?', [$cardId->toBinary()], [ParameterType::BINARY]);
        self::assertIsArray($row);

        return ['reps' => self::int($row['reps']), 'lapses' => self::int($row['lapses'])];
    }

    private function cardCount(Repertoire $repertoire): int
    {
        return self::int($this->connection()->fetchOne('SELECT COUNT(*) FROM repertoire_card WHERE repertoire_id = ?', [$repertoire->getId()->toBinary()], [ParameterType::BINARY]));
    }

    private function rowCount(string $sql): int
    {
        return self::int($this->connection()->fetchOne($sql));
    }

    private static function int(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }

    private function reviewer(): Reviewer
    {
        return self::getContainer()->get(Reviewer::class);
    }

    private function dueQuery(): DueQuery
    {
        return self::getContainer()->get(DueQuery::class);
    }
}
