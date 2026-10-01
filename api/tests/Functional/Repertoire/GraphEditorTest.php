<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repertoire;

use App\Chess\Rules;
use App\Enum\Repertoire\Color;
use App\Enum\Repertoire\MoveRole;
use App\Repertoire\Exception\IllegalMoveException;
use App\Repertoire\Exception\InvalidAnnotationException;
use App\Repertoire\Exception\LimitReachedException;
use App\Repertoire\Exception\NothingToUndoException;
use App\Repertoire\Exception\PositionNotFoundException;
use App\Repertoire\Exception\PositionOccupiedException;
use App\Repertoire\Exception\RepeatedPositionException;
use App\Repertoire\Exception\RepertoireNotFoundException;
use App\Repertoire\Exception\RestoreImpossibleException;
use App\Repertoire\Exception\StaleVersionException;
use App\Repertoire\Exception\TrashNotFoundException;
use App\Repertoire\Graph\GraphEditor;
use App\Repertoire\Graph\GraphIndexer;
use App\Repertoire\Graph\GraphLoader;
use App\Repertoire\Graph\IndexWriter;
use App\Repertoire\Graph\SegmentReconciler;
use App\Repertoire\Limits;
use App\Repertoire\RepertoireManager;
use App\Repertoire\Transaction;
use App\Repository\Repertoire\MoveRepository;
use App\Repository\Repertoire\PositionRepository;
use App\Repository\Repertoire\RepertoireRepository;
use App\Repository\Repertoire\RevisionRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class GraphEditorTest extends KernelTestCase
{
    use RepertoireTestTrait;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testANewRepertoireHasItsInitialPositionOnly(): void
    {
        $repertoire = $this->createRepertoire($this->createUser(), Color::Black, '  Noirs contre 1.d4  ');

        self::assertSame('Noirs contre 1.d4', $repertoire->getName());
        self::assertSame(1, $repertoire->getPositionCount());
        self::assertSame(0, $repertoire->getVersion());
        self::assertSame('rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq -', $this->root($repertoire)->getFen());
        self::assertSame([], $this->segments($repertoire));
    }

    public function testTheNumberOfRepertoiresIsLimited(): void
    {
        $user = $this->createUser();
        $manager = new RepertoireManager($this->em(), self::getContainer()->get(RepertoireRepository::class), new Limits(maxRepertoires: 2), self::getContainer()->get(ClockInterface::class), self::getContainer()->get(Transaction::class));
        $manager->create($user, 'A', Color::White);
        $manager->create($user, 'B', Color::Black);

        $this->expectException(LimitReachedException::class);
        $manager->create($user, 'C', Color::White);
    }

    public function testRolesFollowTheSideToMove(): void
    {
        $repertoire = $this->createRepertoire($this->createUser());
        $this->line($repertoire, 'e4 e5 Nf3');
        $this->line($repertoire, 'e4 c5');

        self::assertSame(MoveRole::Reference, $this->move($this->moveId($repertoire, '', 'e4'))->getRole());
        self::assertSame(MoveRole::Reply, $this->move($this->moveId($repertoire, 'e4', 'e5'))->getRole());
        self::assertSame(MoveRole::Reply, $this->move($this->moveId($repertoire, 'e4', 'c5'))->getRole());
        self::assertSame(1, $this->move($this->moveId($repertoire, 'e4', 'c5'))->getSortOrder());
        self::assertSame(5, $this->em()->getRepository(\App\Entity\Repertoire\Repertoire::class)->find($repertoire->getId())?->getPositionCount());
    }

    public function testASecondPreparedMoveInAPositionIsRefused(): void
    {
        $repertoire = $this->createRepertoire($this->createUser());
        $this->line($repertoire, 'e4 e5');

        try {
            $this->line($repertoire, 'd4');
            self::fail('Two prepared moves in one position.');
        } catch (PositionOccupiedException $e) {
            self::assertSame($this->moveId($repertoire, '', 'e4'), $e->preparedMoveId, 'the prepared move is named');
        }

        // The database refuses it too.
        $this->expectException(UniqueConstraintViolationException::class);
        $this->connection()->executeStatement(
            "INSERT INTO repertoire_move (id, repertoire_id, from_position_id, to_position_id, uci, san, role, sort_order, nags, canonical, created_at)
             SELECT ?, repertoire_id, from_position_id, to_position_id, 'd2d4', 'd4', 'reference', 1, '[]', 0, created_at FROM repertoire_move WHERE id = ?",
            [Uuid::v7()->toBinary(), Uuid::fromString($this->moveId($repertoire, '', 'e4'))->toBinary()],
            [ParameterType::BINARY, ParameterType::BINARY],
        );
    }

    public function testPlayingAMoveAlreadyThereChangesNothing(): void
    {
        $repertoire = $this->createRepertoire($this->createUser());
        [$first] = $this->line($repertoire, 'e4');
        [$again] = $this->line($repertoire, 'e4');

        self::assertSame($first->moveId, $again->moveId);
        self::assertSame('none', $again->operation);
        self::assertSame(1, $again->version);
    }

    public function testAnIllegalMoveIsRefused(): void
    {
        $repertoire = $this->createRepertoire($this->createUser());

        $this->expectException(IllegalMoveException::class);
        $this->editor()->addMove($repertoire->getUser(), $repertoire->getId(), $this->root($repertoire)->getId(), 'e2e5');
    }

    public function testAPositionOfAnotherRepertoireIsRefused(): void
    {
        $user = $this->createUser();
        $mine = $this->createRepertoire($user);
        $other = $this->createRepertoire($user);

        $this->expectException(PositionNotFoundException::class);
        $this->editor()->addMove($user, $mine->getId(), $this->root($other)->getId(), 'e2e4');
    }

    public function testAnotherUsersRepertoireDoesNotExist(): void
    {
        $repertoire = $this->createRepertoire($this->createUser());
        $intruder = $this->createUser('mallory@example.com');

        $this->expectException(RepertoireNotFoundException::class);
        $this->editor()->addMove($intruder, $repertoire->getId(), $this->root($repertoire)->getId(), 'e2e4');
    }

    public function testATranspositionJoinsTheExistingPosition(): void
    {
        $repertoire = $this->createRepertoire($this->createUser(), Color::Black);
        $this->line($repertoire, 'd4 Nf6 c4 e6 Nf3 d5');
        $changes = $this->line($repertoire, 'd4 Nf6 Nf3 e6 c4');
        $last = end($changes);

        self::assertNotFalse($last);
        self::assertTrue($last->transposition);
        $this->em()->clear();
        $transposition = $this->move((string) $last->moveId);
        self::assertFalse($transposition->isCanonical());
        $canonical = $this->connection()->fetchOne(
            'SELECT id FROM repertoire_move WHERE canonical = 1 AND to_position_id = ?',
            [$transposition->getTo()->getId()->toBinary()],
            [ParameterType::BINARY],
        );
        self::assertIsString($canonical);
        self::assertSame($this->moveId($repertoire, 'd4 Nf6 c4 e6', 'Nf3'), Uuid::fromBinary($canonical)->toRfc4122());
        // 1 + 6 positions of the first line, 2 more for 2.Nf3 e6: 3.c4 reaches an existing one.
        self::assertSame(9, $this->rows($repertoire, 'repertoire_position'));
        self::assertSame(['trunk: d4 Nf6 (1 user moves)', 'c4 e6 Nf3 d5 (2 user moves)', 'Nf3 e6 c4 (1 user moves)'], array_values($this->segments($repertoire)));
    }

    public function testAMoveBackToAPositionOfTheLineIsRefused(): void
    {
        $repertoire = $this->createRepertoire($this->createUser());
        $this->line($repertoire, 'Nf3 Nf6 Ng1');

        $this->expectException(RepeatedPositionException::class);
        $this->line($repertoire, 'Nf3 Nf6 Ng1 Ng8');
    }

    public function testPositionAndDepthLimits(): void
    {
        $repertoire = $this->createRepertoire($this->createUser());
        $editor = $this->editorWith(new Limits(maxPositions: 4, maxDepth: 2));
        $root = $this->root($repertoire);
        $user = $repertoire->getUser();

        $e4 = $editor->addMove($user, $repertoire->getId(), $root->getId(), 'e2e4');
        $after = $this->move((string) $e4->moveId)->getTo();
        $e5 = $editor->addMove($user, $repertoire->getId(), $after->getId(), 'e7e5');
        try {
            $editor->addMove($user, $repertoire->getId(), $this->move((string) $e5->moveId)->getTo()->getId(), 'g1f3');
            self::fail('Depth limit not applied.');
        } catch (LimitReachedException $e) {
            self::assertSame('depth', $e->limit);
        }
        $editor->addMove($user, $repertoire->getId(), $after->getId(), 'c7c5');
        try {
            $editor->addMove($user, $repertoire->getId(), $after->getId(), 'd7d5');
            self::fail('Position limit not applied.');
        } catch (LimitReachedException $e) {
            self::assertSame('positions', $e->limit);
        }
    }

    public function testDeletingAMoveSendsWhatOnlyItReachedToTheTrashAndReassignsTheCanonicalPath(): void
    {
        $repertoire = $this->createRepertoire($this->createUser(), Color::Black);
        $this->line($repertoire, 'd4 Nf6 c4 e6 Nf3 d5 Bg5 Be7');
        $this->line($repertoire, 'd4 Nf6 c4 e6 Nc3');
        $this->line($repertoire, 'd4 Nf6 Nf3 e6 c4');
        $segments = $this->segments($repertoire);
        $trunkId = (string) array_key_first($segments);
        $nf3SegmentId = (string) array_search('Nf3 e6 c4 (1 user moves)', $segments, true);

        $change = $this->editor()->delete($repertoire->getUser(), $repertoire->getId(), Uuid::fromString($this->moveId($repertoire, 'd4 Nf6', 'c4')));

        self::assertSame('delete', $change->operation);
        // After 2.c4, 2...e6 and 3.Nc3 go; 2...e6 3.Nf3 is still reached by 2.Nf3 e6 3.c4.
        self::assertCount(3, $change->deletedPositions);
        self::assertSame([[
            'id' => $change->trashId,
            'reason' => 'deleted',
            'san' => 'c4',
            'path' => '["d4", "Nf6"]',
            'position_count' => 3,
            'move_count' => 4,
        ]], $this->trash($repertoire));
        self::assertSame(12 - 3, $this->rows($repertoire, 'repertoire_position'));
        $segments = $this->segments($repertoire);
        // No branching point left after 1...Nf6: everything is the trunk, the transposition is now canonical.
        self::assertSame([$trunkId => 'trunk: d4 Nf6 Nf3 e6 c4 d5 Bg5 Be7 (4 user moves)'], $segments);
        $archived = $this->connection()->fetchAssociative('SELECT HEX(merged_into_segment_id) merged, archived_at FROM repertoire_segment WHERE id = ?', [Uuid::fromString($nf3SegmentId)->toBinary()], [ParameterType::BINARY]);
        self::assertNotNull($archived['archived_at'] ?? null);
        self::assertSame(strtoupper(str_replace('-', '', $trunkId)), $archived['merged'] ?? null, 'merged into the trunk');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function operations(): iterable
    {
        yield 'add a move and a position' => ['add'];
        yield 'add a transposition' => ['transposition'];
        yield 'delete with orphans and a canonical move' => ['delete'];
        yield 'replace a prepared move' => ['replace'];
        yield 'restore from the trash, replacing a move' => ['restore'];
        yield 'promote a variation' => ['promote'];
        yield 'annotate' => ['annotate'];
    }

    #[DataProvider('operations')]
    public function testUndoRestoresTheExactPreviousState(string $operation): void
    {
        $repertoire = $this->createRepertoire($this->createUser(), Color::Black);
        $user = $repertoire->getUser();
        $this->line($repertoire, 'd4 Nf6 c4 e6 Nf3 d5 Bg5 Be7');
        $this->line($repertoire, 'd4 Nf6 c4 e6 Nc3');
        $this->line($repertoire, 'd4 Nf6 Nf3 e6');
        $this->line($repertoire, 'c4 e5');
        $this->editor()->delete($user, $repertoire->getId(), Uuid::fromString($this->moveId($repertoire, 'c4', 'e5')));
        $this->line($repertoire, 'c4 c5');
        $trashId = $this->trash($repertoire)[0]['id'];
        $this->editor()->annotate($user, $repertoire->getId(), Uuid::fromString($this->moveId($repertoire, 'd4 Nf6 c4', 'e6')), 'Solide.', [1]);
        $this->em()->clear();
        $before = $this->snapshot($repertoire);
        $id = fn (string $path, string $san): Uuid => Uuid::fromString($this->moveId($repertoire, $path, $san));

        match ($operation) {
            'add' => $this->line($repertoire, 'd4 Nf6 c4 e6 g3'),
            'transposition' => $this->line($repertoire, 'd4 Nf6 Nf3 e6 c4'),
            'delete' => $this->editor()->delete($user, $repertoire->getId(), $id('d4 Nf6', 'c4')),
            'replace' => $this->editor()->replace($user, $repertoire->getId(), $id('d4 Nf6 c4', 'e6'), 'g7g6'),
            'restore' => $this->editor()->restore($user, $repertoire->getId(), $trashId),
            'promote' => $this->editor()->promote($user, $repertoire->getId(), $id('d4 Nf6', 'Nf3')),
            'annotate' => $this->editor()->annotate($user, $repertoire->getId(), $id('d4 Nf6 c4', 'e6'), 'Autre.', [5, 14]),
            default => self::fail($operation),
        };
        $this->em()->clear();
        self::assertNotSame($before, $this->snapshot($repertoire));

        $undo = $this->editor()->undo($user, $repertoire->getId());
        $this->em()->clear();

        self::assertSame('undo', $undo->operation);
        self::assertSame($before, $this->snapshot($repertoire));
    }

    public function testUndoIsAStackOfLimitedDepth(): void
    {
        $repertoire = $this->createRepertoire($this->createUser());
        $user = $repertoire->getUser();
        $editor = $this->editorWith(new Limits(undoDepth: 2));
        $position = $this->root($repertoire);
        foreach (['e2e4', 'e7e5', 'g1f3'] as $uci) {
            $position = $this->move((string) $editor->addMove($user, $repertoire->getId(), $position->getId(), $uci)->moveId)->getTo();
        }

        $editor->undo($user, $repertoire->getId());
        $editor->undo($user, $repertoire->getId());
        $this->em()->clear();
        self::assertSame(2, $this->rows($repertoire, 'repertoire_position'), 'only 1.e4 is left');

        $this->expectException(NothingToUndoException::class);
        $editor->undo($user, $repertoire->getId());
    }

    public function testAChangeBasedOnAnOlderVersionIsRefused(): void
    {
        $repertoire = $this->createRepertoire($this->createUser());
        [$e4] = $this->line($repertoire, 'e4');

        try {
            $this->editor()->addMove($repertoire->getUser(), $repertoire->getId(), $this->move((string) $e4->moveId)->getTo()->getId(), 'e7e5', baseVersion: 0);
            self::fail('Stale version accepted.');
        } catch (StaleVersionException $e) {
            self::assertSame(1, $e->currentVersion);
        }
        $change = $this->editor()->addMove($repertoire->getUser(), $repertoire->getId(), $this->move((string) $e4->moveId)->getTo()->getId(), 'e7e5', baseVersion: $e4->version);
        self::assertSame(2, $change->version);
    }

    public function testReplacingAPreparedMoveSendsItsSuiteToTheTrash(): void
    {
        $repertoire = $this->createRepertoire($this->createUser());
        $user = $repertoire->getUser();
        $this->line($repertoire, 'e4 e5 Nf3 Nc6 Bb5');
        $this->line($repertoire, 'e4 c5 Nf3');
        $e4 = $this->moveId($repertoire, '', 'e4');
        $this->editor()->annotate($user, $repertoire->getId(), Uuid::fromString($this->moveId($repertoire, 'e4 e5 Nf3 Nc6', 'Bb5')), 'Espagnole.', [1]);

        $change = $this->editor()->replace($user, $repertoire->getId(), Uuid::fromString($e4), 'd2d4');
        $this->em()->clear();

        self::assertSame('replace', $change->operation);
        self::assertSame(MoveRole::Reference, $this->move((string) $change->moveId)->getRole());
        self::assertSame(['trunk: d4 (1 user moves)'], array_values($this->segments($repertoire)));
        self::assertSame(2, $this->rows($repertoire, 'repertoire_position'));
        self::assertSame([[
            'id' => $change->trashId,
            'reason' => 'replaced',
            'san' => 'e4',
            'path' => '[]',
            'position_count' => 7,
            'move_count' => 7,
        ]], $this->trash($repertoire));

        // Restored: 1.d4 goes to the trash in turn, 1.e4 comes back with its ids and annotations.
        $restore = $this->editor()->restore($user, $repertoire->getId(), (string) $change->trashId);
        $this->em()->clear();

        self::assertSame('restore', $restore->operation);
        self::assertSame($e4, $this->moveId($repertoire, '', 'e4'));
        self::assertSame('Espagnole.', $this->move($this->moveId($repertoire, 'e4 e5 Nf3 Nc6', 'Bb5'))->getComment());
        self::assertSame(['trunk: e4 (1 user moves)', 'e5 Nf3 Nc6 Bb5 (2 user moves)', 'c5 Nf3 (1 user moves)'], array_values($this->segments($repertoire)));
        self::assertSame([['reason' => 'replaced', 'san' => 'd4']], array_map(static fn (array $row): array => ['reason' => $row['reason'], 'san' => $row['san']], $this->trash($repertoire)));
    }

    public function testAReplacementJoiningAPositionOfTheFormerSuiteKeepsItAndIsUndone(): void
    {
        $repertoire = $this->createRepertoire($this->createUser());
        $user = $repertoire->getUser();
        // 1.Nf3 Nf6 2.Nc3 Ng8 3.Ng1 is 1.Nc3 by another move order.
        $this->line($repertoire, 'Nf3 Nf6 Nc3 Ng8 Ng1 e5 e4');
        $this->em()->clear();
        $before = $this->snapshot($repertoire);
        $afterNc3 = $this->move($this->moveId($repertoire, 'Nf3 Nf6 Nc3 Ng8', 'Ng1'))->getTo()->getId()->toRfc4122();

        $change = $this->editor()->replace($user, $repertoire->getId(), Uuid::fromString($this->moveId($repertoire, '', 'Nf3')), 'b1c3');
        $this->em()->clear();

        self::assertSame($afterNc3, $this->move((string) $change->moveId)->getTo()->getId()->toRfc4122(), 'the position is joined');
        self::assertSame(['trunk: Nc3 e5 e4 (2 user moves)'], array_values($this->segments($repertoire)));
        self::assertSame(4, $this->trash($repertoire)[0]['position_count'], 'after 1.Nf3, 1...Nf6, 2.Nc3 and 2...Ng8');

        $this->editor()->undo($user, $repertoire->getId());
        $this->em()->clear();
        self::assertSame($before, $this->snapshot($repertoire));
        self::assertSame([], $this->trash($repertoire));
    }

    public function testReplacingByTheSameMoveChangesNothing(): void
    {
        $repertoire = $this->createRepertoire($this->createUser());
        [$e4] = $this->line($repertoire, 'e4');

        $change = $this->editor()->replace($repertoire->getUser(), $repertoire->getId(), Uuid::fromString((string) $e4->moveId), 'e2e4');

        self::assertSame('none', $change->operation);
        self::assertSame([], $this->trash($repertoire));
    }

    public function testAReplyIsNotReplaced(): void
    {
        $repertoire = $this->createRepertoire($this->createUser());
        $this->line($repertoire, 'e4 e5');

        $this->expectException(IllegalMoveException::class);
        $this->editor()->replace($repertoire->getUser(), $repertoire->getId(), Uuid::fromString($this->moveId($repertoire, 'e4', 'e5')), 'c7c5');
    }

    public function testARestorationConflictDeeperInTheSuiteKeepsTheCurrentMoveUnlessChosen(): void
    {
        $repertoire = $this->createRepertoire($this->createUser());
        $user = $repertoire->getUser();
        $this->line($repertoire, 'e4 e5 Nf3 Nc6 Bb5 a6 Ba4');
        $deleted = $this->editor()->delete($user, $repertoire->getId(), Uuid::fromString($this->moveId($repertoire, 'e4 e5', 'Nf3')));
        $this->line($repertoire, 'e4 e5 Nf3 Nc6 Bc4');
        $trashId = (string) $deleted->trashId;
        $afterNc6 = Rules::fromFen('r1bqkbnr/pppp1ppp/2n5/4p3/4P3/5N2/PPPP1PPP/RNBQKB1R w KQkq - 2 3')->normalizedFen();

        [, $plan] = $this->editor()->previewRestore($user, $repertoire->getId(), $trashId);
        self::assertSame([[
            'fen' => $afterNc6,
            'path' => ['e4', 'e5', 'Nf3', 'Nc6'],
            'restored' => ['uci' => 'f1b5', 'san' => 'Bb5'],
            'current' => ['uci' => 'f1c4', 'san' => 'Bc4'],
            'choice' => 'current',
        ]], $plan->conflicts);
        self::assertSame(2, $plan->joined, '2.Nf3 Nc6 are already there');
        self::assertSame(1, $plan->leftOut);
        self::assertSame([], $plan->moves);

        [, $chosen] = $this->editor()->previewRestore($user, $repertoire->getId(), $trashId, [$afterNc6 => 'restored']);
        self::assertSame('restored', $chosen->conflicts[0]['choice']);
        self::assertCount(3, $chosen->moves, '3.Bb5 a6 4.Ba4');
        self::assertCount(1, $chosen->replaced);

        $this->editor()->restore($user, $repertoire->getId(), $trashId, [$afterNc6 => 'restored']);
        $this->em()->clear();
        self::assertSame(['trunk: e4 e5 Nf3 Nc6 Bb5 a6 Ba4 (4 user moves)'], array_values($this->segments($repertoire)));
        self::assertSame([['reason' => 'replaced', 'san' => 'Bc4']], array_map(static fn (array $row): array => ['reason' => $row['reason'], 'san' => $row['san']], $this->trash($repertoire)));
    }

    public function testASuiteWhoseStartIsGoneCannotBeRestoredYet(): void
    {
        $repertoire = $this->createRepertoire($this->createUser());
        $user = $repertoire->getUser();
        $this->line($repertoire, 'e4 e5 Nf3 Nc6');
        $inner = $this->editor()->delete($user, $repertoire->getId(), Uuid::fromString($this->moveId($repertoire, 'e4 e5', 'Nf3')));
        $outer = $this->editor()->delete($user, $repertoire->getId(), Uuid::fromString($this->moveId($repertoire, '', 'e4')));

        try {
            $this->editor()->restore($user, $repertoire->getId(), (string) $inner->trashId);
            self::fail('Restored without its start position.');
        } catch (RestoreImpossibleException) {
            self::addToAssertionCount(1);
        }
        $this->editor()->restore($user, $repertoire->getId(), (string) $outer->trashId);
        $this->editor()->restore($user, $repertoire->getId(), (string) $inner->trashId);
        $this->em()->clear();

        self::assertSame(['trunk: e4 e5 Nf3 Nc6 (2 user moves)'], array_values($this->segments($repertoire)));
        self::assertSame([], $this->trash($repertoire));
    }

    public function testADiscardedSuiteIsGoneAndItsChangeCanNoLongerBeUndone(): void
    {
        $repertoire = $this->createRepertoire($this->createUser());
        $user = $repertoire->getUser();
        $this->line($repertoire, 'e4 e5');
        $deleted = $this->editor()->delete($user, $repertoire->getId(), Uuid::fromString($this->moveId($repertoire, '', 'e4')));

        $this->editor()->discard($user, $repertoire->getId(), (string) $deleted->trashId);
        self::assertSame([], $this->trash($repertoire));

        try {
            $this->editor()->undo($user, $repertoire->getId());
            self::fail('Undone without its trash entry.');
        } catch (NothingToUndoException) {
            self::addToAssertionCount(1);
        }
        $this->expectException(TrashNotFoundException::class);
        $this->editor()->discard($user, $repertoire->getId(), (string) $deleted->trashId);
    }

    public function testPromotingAVariationPutsItFirst(): void
    {
        $repertoire = $this->createRepertoire($this->createUser());
        $this->line($repertoire, 'e4 e5');
        $this->line($repertoire, 'e4 c5');
        $this->line($repertoire, 'e4 e6');

        $this->editor()->promote($repertoire->getUser(), $repertoire->getId(), Uuid::fromString($this->moveId($repertoire, 'e4', 'e6')));
        $this->em()->clear();

        self::assertSame([1, 2, 0], [
            $this->move($this->moveId($repertoire, 'e4', 'e5'))->getSortOrder(),
            $this->move($this->moveId($repertoire, 'e4', 'c5'))->getSortOrder(),
            $this->move($this->moveId($repertoire, 'e4', 'e6'))->getSortOrder(),
        ]);
    }

    public function testCommentsArePlainTextAndAnnotationsAreChecked(): void
    {
        $repertoire = $this->createRepertoire($this->createUser());
        [$e4] = $this->line($repertoire, 'e4');
        $id = Uuid::fromString((string) $e4->moveId);
        $user = $repertoire->getUser();

        $this->editor()->annotate($user, $repertoire->getId(), $id, "  <script>alert(1)</script>\r\nMain\x07 line ", [14, 1, 1]);
        $this->em()->clear();
        $move = $this->move((string) $e4->moveId);
        // Stored as text (the front displays it as text): only control characters are removed.
        self::assertSame("<script>alert(1)</script>\nMain line", $move->getComment());
        self::assertSame([1, 14], $move->getNags());

        foreach ([[str_repeat('a', 2001), []], [null, [1, 2]], [null, [256]], [null, [0]], [null, [10, 11, 12, 13, 14]]] as [$comment, $nags]) {
            try {
                $this->editor()->annotate($user, $repertoire->getId(), $id, $comment, $nags);
                self::fail('Accepted: '.json_encode([$comment, $nags]));
            } catch (InvalidAnnotationException) {
                self::addToAssertionCount(1);
            }
        }
        $this->editor()->annotate($user, $repertoire->getId(), $id, '   ', []);
        $this->em()->clear();
        self::assertNull($this->move((string) $e4->moveId)->getComment());
    }

    public function testAPositionIsFoundInTheUsersRepertoiresByItsDigest(): void
    {
        $user = $this->createUser();
        $first = $this->createRepertoire($user, Color::White, 'A');
        $second = $this->createRepertoire($user, Color::Black, 'B');
        $this->line($first, 'e4 e5 Nf3');
        $this->line($second, 'Nf3 Nc6 e4 e5');
        $this->line($this->createRepertoire($this->createUser('bob@example.com')), 'e4 e5 Nf3 Nc6');

        $hash = \App\Chess\Position\PositionKey::of('rnbqkbnr/pppp1ppp/8/4p3/4P3/5N2/PPPP1PPP/RNBQKB1R b KQkq -')->hash;
        $found = $this->connection()->fetchFirstColumn(
            'SELECT repertoire_id FROM repertoire_position WHERE user_id = ? AND fen_hash = ?',
            [$user->getId()->toBinary(), $hash],
            [ParameterType::BINARY, ParameterType::BINARY],
        );

        self::assertCount(1, $found, 'after 1.e4 e5 2.Nf3 (black to move): only in A for this user');
    }

    public function testDeletingARepertoireDeletesItsGraph(): void
    {
        $repertoire = $this->createRepertoire($this->createUser());
        $this->line($repertoire, 'e4 e5 Nf3');

        self::getContainer()->get(RepertoireManager::class)->delete($repertoire->getUser(), $repertoire->getId());

        self::assertSame(0, $this->rows($repertoire, 'repertoire_position'));
        self::assertSame(0, $this->rows($repertoire, 'repertoire_move'));
        self::assertSame(0, $this->rows($repertoire, 'repertoire_segment'));
    }

    /**
     * The trash of a repertoire, oldest first.
     *
     * @return list<array{id: string, reason: string, san: string, path: string, position_count: int, move_count: int}>
     */
    private function trash(\App\Entity\Repertoire\Repertoire $repertoire): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT id, reason, san, path, position_count, move_count FROM repertoire_trash WHERE repertoire_id = ? ORDER BY created_at, id',
            [$repertoire->getId()->toBinary()],
            [ParameterType::BINARY],
        );

        return array_map(static function (array $row): array {
            self::assertIsString($row['id']);
            self::assertIsString($row['reason']);
            self::assertIsString($row['san']);
            self::assertIsString($row['path']);
            self::assertIsNumeric($row['position_count']);
            self::assertIsNumeric($row['move_count']);

            return [
                'id' => Uuid::fromBinary($row['id'])->toRfc4122(),
                'reason' => $row['reason'],
                'san' => $row['san'],
                'path' => $row['path'],
                'position_count' => (int) $row['position_count'],
                'move_count' => (int) $row['move_count'],
            ];
        }, $rows);
    }

    private function rows(\App\Entity\Repertoire\Repertoire $repertoire, string $table): int
    {
        $count = $this->connection()->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE repertoire_id = ?', $table), [$repertoire->getId()->toBinary()], [ParameterType::BINARY]);

        return is_numeric($count) ? (int) $count : -1;
    }
}
