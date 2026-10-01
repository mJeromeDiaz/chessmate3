<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repertoire;

use App\Chess\Rules;
use App\Entity\Repertoire\Repertoire;
use App\Entity\User;
use App\Enum\Repertoire\Color;
use App\Repertoire\Exception\LimitReachedException;
use App\Repertoire\Import\ImportApplier;
use App\Repertoire\Import\ImportedTree;
use App\Repertoire\Import\ImportPlanner;
use App\Repertoire\Import\PgnAnalyzer;
use App\Repertoire\Limits;
use App\Repertoire\Pgn\Exporter;
use App\Repertoire\RepertoireManager;
use App\Repertoire\Transaction;
use App\Repository\Repertoire\RepertoireRepository;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Applying imports: new or existing repertoire, conflicts, annotations, undo, round trip.
 */
final class ImportApplierTest extends KernelTestCase
{
    use RepertoireTestTrait;

    public function testTheOpenBookRepertoireImportsToThreeLinesAndFiveSegments(): void
    {
        $user = $this->createUser();
        $id = $this->applier()->apply($user, $this->analyzed((string) file_get_contents(__DIR__.'/../../Fixtures/Chess/openbook-white.pgn')), ['name' => 'OpenBook', 'color' => Color::White]);
        $this->em()->clear();
        $repertoire = $this->repertoire($id);

        self::assertSame('OpenBook', $repertoire->getName());
        self::assertSame(40, $repertoire->getPositionCount());
        self::assertSame(3, $this->lineEnds($repertoire));
        $segments = array_values($this->segments($repertoire));
        sort($segments);
        self::assertSame([
            'Nc6 e3 Bf5 f3 e6 g4 Bg6 h4 (4 user moves)',
            'e6 e3 c5 Nb5 Qa5+ b4 Qxb4+ c3 Qa5 Bc7 b6 Nd6+ Bxd6 Bxd6 (7 user moves)',
            'h5 g5 Nd7 Bd3 Bxd3 Qxd3 Bb4 Ne2 Qe7 g6 (5 user moves)',
            'h6 Bd3 (1 user moves)',
            'trunk: d4 d5 Nc3 Nf6 Bf4 (3 user moves)',
        ], $segments);
    }

    public function testMergingFollowsTheChoicesFillsEmptyAnnotationsAndUndoesInOneStep(): void
    {
        $user = $this->createUser();
        $repertoire = $this->createRepertoire($user);
        $this->line($repertoire, 'e4 e5 Nf3');
        $e4 = $this->moveId($repertoire, '', 'e4');
        $e5 = $this->moveId($repertoire, 'e4', 'e5');
        $this->editor()->annotate($user, $repertoire->getId(), Uuid::fromString($e5), 'Mine', []);
        $this->em()->clear();
        $version = $this->repertoire($repertoire->getId())->getVersion();

        // No choice: the repertoire's 1.e4 stays, the file's 1.d4 is not imported.
        $tree = $this->analyzed('1. d4 {Closed} (1. e4 $1 {Best by test} e5 {Theirs} $2 2. Nf3 Nc6 3. Bb5) 1... d5 *');
        $this->applier()->apply($user, $tree, ['repertoireId' => $repertoire->getId()]);
        $this->em()->clear();
        $imported = $this->repertoire($repertoire->getId());

        self::assertSame($version + 1, $imported->getVersion(), 'one change');
        self::assertSame(['reference', 0, 'Best by test'], $this->roleOrderComment($e4), 'the empty comment was filled');
        self::assertSame(['reply', 0, 'Mine'], $this->roleOrderComment($e5), 'an existing comment stays');
        self::assertSame('[1]', $this->column($e4, 'nags'));
        self::assertSame('[2]', $this->column($e5, 'nags'), 'its NAGs were empty: filled (comment and NAGs separately)');
        self::assertSame(['trunk: e4 e5 Nf3 Nc6 Bb5 (3 user moves)'], array_values($this->segments($imported)));
        self::assertSame(6, $imported->getPositionCount());
        self::assertSame(0, $this->trashCount($imported));
    }

    public function testAFileMoveChosenSendsTheRepertoiresMoveToTheTrash(): void
    {
        $user = $this->createUser();
        $repertoire = $this->createRepertoire($user);
        $this->line($repertoire, 'e4 e5 Nf3');

        $tree = $this->analyzed('1. d4 {Closed} (1. e4 e5 2. Nf3 Nc6 3. Bb5) 1... d5 *');
        $this->applier()->apply($user, $tree, ['repertoireId' => $repertoire->getId()], [Rules::initial()->normalizedFen() => 'd2d4']);
        $this->em()->clear();
        $imported = $this->repertoire($repertoire->getId());

        self::assertSame(['reference', 0, 'Closed'], $this->roleOrderComment($this->moveId($imported, '', 'd4')));
        self::assertSame(['trunk: d4 d5 (1 user moves)'], array_values($this->segments($imported)), 'the file\'s 1.e4 line is not imported');
        self::assertSame(1, $this->trashCount($imported));
        self::assertSame(['imported', 'e4', 3], $this->connection()->fetchNumeric('SELECT reason, san, position_count FROM repertoire_trash WHERE repertoire_id = ?', [$imported->getId()->toBinary()], [ParameterType::BINARY]) ?: null);
    }

    public function testUndoingAnImportRestoresTheRepertoireExactly(): void
    {
        $user = $this->createUser();
        $repertoire = $this->createRepertoire($user);
        $this->line($repertoire, 'e4 e5 Nf3');
        $this->em()->clear();
        $before = $this->snapshot($this->repertoire($repertoire->getId()));

        $tree = $this->analyzed('1. d4 {Closed} (1. e4 $1 {Best by test} e5 $2 2. Nf3 Nc6 3. Bb5) 1... d5 *');
        $this->applier()->apply($user, $tree, ['repertoireId' => $repertoire->getId()], [Rules::initial()->normalizedFen() => 'd2d4']);
        $this->em()->clear();
        self::assertNotSame($before, $this->snapshot($this->repertoire($repertoire->getId())));

        $change = $this->editor()->undo($user, $repertoire->getId());
        $this->em()->clear();

        self::assertSame($before, $this->snapshot($this->repertoire($repertoire->getId())));
        self::assertCount(2, $change->deletedMoves, '1.d4 d5: what the import created');
        self::assertSame(0, $this->trashCount($this->repertoire($repertoire->getId())), '1.e4 taken back from the trash');
    }

    public function testAnExportedRepertoireImportsBackIdentically(): void
    {
        $user = $this->createUser();
        $repertoire = $this->createRepertoire($user, Color::Black, 'Noirs');
        $this->pgn($repertoire, '1. d4 Nf6 2. c4 (2. Nf3 e6 3. c4 d5) 2... e6 3. Nf3 (3. Nc3 Bb4) 3... d5 4. Nc3 *');
        $this->pgn($repertoire, '1. e4 c5 2. Nf3 (2. Nc3 Nc6) 2... d6 *');
        $this->line($repertoire, 'c4 e5');
        $this->editor()->annotate($user, $repertoire->getId(), Uuid::fromString($this->moveId($repertoire, 'e4', 'c5')), 'Najdorf {plan}', [1, 14]);
        $this->em()->clear();
        $original = $this->repertoire($repertoire->getId());
        $pgn = self::getContainer()->get(Exporter::class)->export($original);

        $user = $this->em()->find(User::class, $user->getId()) ?? throw new \LogicException('No user.');
        $copy = $this->repertoire($this->applier()->apply($user, $this->analyzed($pgn), ['name' => 'Copie', 'color' => Color::Black]));
        $this->em()->clear();

        // PGN comments cannot hold "}": the writer turns it into ")".
        self::assertSame(str_replace('{plan}', '{plan)', $this->shape($original)), $this->shape($copy));
        self::assertSame($pgn, str_replace('[Event "Copie"]', '[Event "Noirs"]', self::getContainer()->get(Exporter::class)->export($this->repertoire($copy->getId()))));
    }

    public function testTheDepthLimitIsCheckedOnTheMergedGraph(): void
    {
        $user = $this->createUser();
        $repertoire = $this->createRepertoire($user);
        $this->line($repertoire, 'e4 e5');
        $this->em()->clear();
        $applier = new ImportApplier(new ImportPlanner(), $this->editorWith(new Limits(maxDepth: 3)), self::getContainer()->get(RepertoireManager::class), self::getContainer()->get(RepertoireRepository::class), $this->connection(), self::getContainer()->get(Transaction::class));
        // A chapter from the position after 1.e4 e5: its first move is the 3rd ply, the next the 4th.
        $chapter = sprintf("[FEN \"%s 0 2\"]\n\n2. Nf3 Nc6 *", $this->fenAfter('e4 e5'));

        try {
            $applier->apply($user, $this->analyzed($chapter), ['repertoireId' => $repertoire->getId()]);
            self::fail('Refusal expected.');
        } catch (LimitReachedException $e) {
            self::assertSame('depth', $e->limit);
        }
        $this->em()->clear();
        self::assertSame(3, $this->repertoire($repertoire->getId())->getPositionCount());
    }

    public function testARefusedImportLeavesNothingBehind(): void
    {
        $user = $this->createUser();
        $count = $this->repertoireCount();

        try {
            $this->applier()->apply($user, $this->analyzed('1. e4 e5 2. Nf3 Nc6 3. Bb5 a6 *'), ['name' => 'Too big', 'color' => Color::White]);
        } catch (LimitReachedException) {
            self::fail('Within the limits.');
        }
        self::assertSame($count + 1, $this->repertoireCount());

        $small = new ImportApplier(
            new ImportPlanner(),
            $this->editorWith(new Limits(maxPositions: 5)),
            self::getContainer()->get(RepertoireManager::class),
            self::getContainer()->get(RepertoireRepository::class),
            $this->connection(),
            self::getContainer()->get(Transaction::class),
        );
        try {
            $small->apply($user, $this->analyzed('1. e4 e5 2. Nf3 Nc6 3. Bb5 a6 *'), ['name' => 'Too big', 'color' => Color::White]);
            self::fail('Refusal expected.');
        } catch (LimitReachedException $e) {
            self::assertSame('positions', $e->limit);
        }
        self::assertSame($count + 1, $this->repertoireCount(), 'the new repertoire was rolled back with the import');
    }

    /**
     * Every move as "from FEN / display rank / SAN role comment nags canonical", sorted.
     *
     * @return list<string>
     */
    private function shape(Repertoire $repertoire): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT p.fen, m.san, m.role, m.sort_order, m.comment, m.nags, m.canonical, HEX(m.id) id
             FROM repertoire_move m JOIN repertoire_position p ON p.id = m.from_position_id
             WHERE m.repertoire_id = ? ORDER BY p.fen, m.sort_order, m.id',
            [$repertoire->getId()->toBinary()],
            [ParameterType::BINARY],
        );
        $rank = [];
        $shape = [];
        foreach ($rows as $row) {
            $fen = self::str($row['fen']);
            $rank[$fen] = ($rank[$fen] ?? -1) + 1;
            $shape[] = sprintf('%s / %d / %s %s %s %s %d', $fen, $rank[$fen], self::str($row['san']), self::str($row['role']), null === $row['comment'] ? '-' : self::str($row['comment']), self::str($row['nags']), self::int($row['canonical']));
        }
        sort($shape);

        return $shape;
    }

    /**
     * @return array{string, int, string|null}
     */
    private function roleOrderComment(string $moveId): array
    {
        $row = $this->connection()->fetchAssociative('SELECT role, sort_order, comment FROM repertoire_move WHERE id = ?', [Uuid::fromString($moveId)->toBinary()], [ParameterType::BINARY]);
        self::assertIsArray($row);

        return [self::str($row['role']), self::int($row['sort_order']), null === $row['comment'] ? null : self::str($row['comment'])];
    }

    private function column(string $moveId, string $column): string
    {
        return self::str($this->connection()->fetchOne(sprintf('SELECT %s FROM repertoire_move WHERE id = ?', $column), [Uuid::fromString($moveId)->toBinary()], [ParameterType::BINARY]));
    }

    private function lineEnds(Repertoire $repertoire): int
    {
        return self::int($this->connection()->fetchOne(
            'SELECT COUNT(DISTINCT m.to_position_id) FROM repertoire_move m WHERE m.repertoire_id = ? AND NOT EXISTS (SELECT 1 FROM repertoire_move n WHERE n.from_position_id = m.to_position_id)',
            [$repertoire->getId()->toBinary()],
            [ParameterType::BINARY],
        ));
    }

    private function fenAfter(string $sans): string
    {
        $rules = Rules::initial();
        foreach (explode(' ', $sans) as $san) {
            $rules->playSan($san) ?? throw new \LogicException($san);
        }

        return $rules->normalizedFen();
    }

    private function trashCount(Repertoire $repertoire): int
    {
        return self::int($this->connection()->fetchOne('SELECT COUNT(*) FROM repertoire_trash WHERE repertoire_id = ?', [$repertoire->getId()->toBinary()], [ParameterType::BINARY]));
    }

    private function repertoireCount(): int
    {
        return self::int($this->connection()->fetchOne('SELECT COUNT(*) FROM repertoire'));
    }

    private static function str(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    private static function int(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }

    private function analyzed(string $pgn): ImportedTree
    {
        return (new PgnAnalyzer(new Limits()))->analyze($pgn);
    }

    private function applier(): ImportApplier
    {
        return self::getContainer()->get(ImportApplier::class);
    }

    private function repertoire(Uuid $id): Repertoire
    {
        return $this->em()->find(Repertoire::class, $id) ?? throw new \LogicException('No repertoire.');
    }
}
