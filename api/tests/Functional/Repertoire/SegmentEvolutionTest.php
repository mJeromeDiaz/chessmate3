<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repertoire;

use App\Entity\Repertoire\Repertoire;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Segments keep their identity (and so their statistics) while the repertoire evolves
 * (docs/REPERTOIRE.md), on the "OpenBook" white repertoire.
 */
final class SegmentEvolutionTest extends KernelTestCase
{
    use RepertoireTestTrait;

    private const NC6 = 'd4 d5 Nc3 Nf6 Bf4';
    private const H4 = 'd4 d5 Nc3 Nf6 Bf4 Nc6 e3 Bf5 f3 e6 g4 Bg6 h4';

    private Repertoire $repertoire;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repertoire = $this->createRepertoire($this->createUser());
        $this->pgn($this->repertoire, (string) file_get_contents(__DIR__.'/../../Fixtures/Chess/openbook-white.pgn'));
    }

    public function testTheImportedRepertoireHasFiveSegments(): void
    {
        self::assertSame([
            'trunk: d4 d5 Nc3 Nf6 Bf4 (3 user moves)',
            'Nc6 e3 Bf5 f3 e6 g4 Bg6 h4 (4 user moves)',
            'h6 Bd3 (1 user moves)',
            'h5 g5 Nd7 Bd3 Bxd3 Qxd3 Bb4 Ne2 Qe7 g6 (5 user moves)',
            'e6 e3 c5 Nb5 Qa5+ b4 Qxb4+ c3 Qa5 Bc7 b6 Nd6+ Bxd6 Bxd6 (7 user moves)',
        ], array_values($this->segments($this->repertoire)));
    }

    public function testAnExtendedSegmentKeepsItsIdentity(): void
    {
        $h6 = $this->segmentOf(self::H4, 'h6');

        $this->line($this->repertoire, self::H4.' h6 Bd3 Bxd3 Qxd3');

        self::assertSame('h6 Bd3 Bxd3 Qxd3 (2 user moves)', $this->segments($this->repertoire)[$h6]);
    }

    public function testANewBranchingPointCutsASegmentInTwo(): void
    {
        $upper = $this->segmentOf(self::NC6, 'Nc6');

        // 4...e6 instead of 4...Bf5: the position after 4.e3 becomes a branching point.
        $this->line($this->repertoire, self::NC6.' Nc6 e3 e6');
        $segments = $this->segments($this->repertoire);
        $lower = $this->segmentOf(self::NC6.' Nc6 e3', 'Bf5');

        self::assertSame('Nc6 e3 (1 user moves)', $segments[$upper]);
        self::assertSame('Bf5 f3 e6 g4 Bg6 h4 (3 user moves)', $segments[$lower]);
        self::assertSame('e6 (0 user moves)', $segments[$this->segmentOf(self::NC6.' Nc6 e3', 'e6')]);
        self::assertSame($upper, $this->column($lower, 'derived_from_segment_id'), 'the lower part derives from the cut segment');

        // Undo: the branching point disappears, the lower part is merged back into the upper one.
        $this->editor()->undo($this->repertoire->getUser(), $this->repertoire->getId());
        self::assertSame('Nc6 e3 Bf5 f3 e6 g4 Bg6 h4 (4 user moves)', $this->segments($this->repertoire)[$upper]);
        self::assertNotNull($this->column($lower, 'archived_at'));
        self::assertSame($upper, $this->column($lower, 'merged_into_segment_id'));

        // The same branching point again: the archived lower part comes back, statistics included.
        $this->line($this->repertoire, self::NC6.' Nc6 e3 e6');
        self::assertSame('Bf5 f3 e6 g4 Bg6 h4 (3 user moves)', $this->segments($this->repertoire)[$lower] ?? null);
        self::assertNull($this->column($lower, 'archived_at'));
    }

    public function testDeletingTheFirstMoveArchivesTheSegment(): void
    {
        $upper = $this->segmentOf(self::NC6, 'Nc6');
        $h6 = $this->segmentOf(self::H4, 'h6');
        $h5 = $this->segmentOf(self::H4, 'h5');

        $this->editor()->delete($this->repertoire->getUser(), $this->repertoire->getId(), Uuid::fromString($this->moveId($this->repertoire, self::H4, 'h5')));

        self::assertNotNull($this->column($h5, 'archived_at'));
        self::assertNull($this->column($h5, 'merged_into_segment_id'), 'its first move is gone');
        // After 7.h4, 7...h6 is now the only reply: no more branching point, 7...h6 joins the segment above.
        self::assertSame($upper, $this->column($h6, 'merged_into_segment_id'));
        self::assertSame('Nc6 e3 Bf5 f3 e6 g4 Bg6 h4 h6 Bd3 (5 user moves)', $this->segments($this->repertoire)[$upper]);
        self::assertCount(3, $this->segments($this->repertoire));
    }

    private function segmentOf(string $path, string $san): string
    {
        // Segments are written in plain SQL: forget the entities loaded before.
        $this->em()->clear();
        $segment = $this->move($this->moveId($this->repertoire, $path, $san))->getSegment();

        return $segment?->getId()->toRfc4122() ?? throw new \LogicException('Not in a segment: '.$san);
    }

    private function column(string $segmentId, string $column): ?string
    {
        $value = $this->connection()->fetchOne(sprintf('SELECT %s FROM repertoire_segment WHERE id = ?', $column), [Uuid::fromString($segmentId)->toBinary()], [ParameterType::BINARY]);
        if (null === $value || false === $value) {
            return null;
        }
        self::assertIsString($value);

        return str_ends_with($column, '_id') ? Uuid::fromBinary($value)->toRfc4122() : $value;
    }
}
