<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repertoire;

use App\Chess\Position\PositionKey;
use App\Repertoire\Opening\OpeningSynchronizer;
use App\Repository\Repertoire\OpeningRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OpeningSynchronizerTest extends KernelTestCase
{
    private const FIXTURES = __DIR__.'/../../Fixtures/Chess/openings';

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testOpeningsAreLoadedByPositionIdempotently(): void
    {
        $synchronizer = self::getContainer()->get(OpeningSynchronizer::class);

        // 1.e4 e5 appears twice, and 1.d4 d5 2.Nf3 Nf6 3.Ng1 Ng8 reaches 1.d4 d5: first one kept.
        $first = $synchronizer->sync(self::FIXTURES);
        $again = $synchronizer->sync(self::FIXTURES);
        self::assertSame(['loaded' => 6, 'duplicates' => 2, 'removed' => 0], $first);
        self::assertEquals($first, $again, 'idempotent');
        self::assertSame(6, self::getContainer()->get(OpeningRepository::class)->count([]));

        $openings = self::getContainer()->get(OpeningRepository::class);
        $open = $openings->findByKey(PositionKey::of('rnbqkbnr/pppp1ppp/8/4p3/4P3/8/PPPP1PPP/RNBQKBNR w KQkq -'));
        self::assertSame(['C20', "King's Pawn Game: Open Game", 'e2e4 e7e5'], [$open?->getEco(), $open?->getName(), $open?->getUci()]);
        self::assertSame("Queen's Pawn Game: Double Queen Pawn", $openings->findByKey(PositionKey::of('rnbqkbnr/ppp1pppp/8/3p4/3P4/8/PPP1PPPP/RNBQKBNR w KQkq -'))?->getName());
    }

    public function testOpeningsGoneFromTheFilesAreRemoved(): void
    {
        $directory = sys_get_temp_dir().'/openings-'.bin2hex(random_bytes(4));
        mkdir($directory);
        foreach (OpeningSynchronizer::FILES as $file) {
            copy(self::FIXTURES.'/'.$file, $directory.'/'.$file);
        }
        $synchronizer = self::getContainer()->get(OpeningSynchronizer::class);
        $synchronizer->sync($directory);
        file_put_contents($directory.'/a.tsv', "eco\tname\tpgn\nA40\tQueen's Pawn Game\t1. d4\n");

        self::assertSame(1, $synchronizer->sync($directory)['removed'], 'the London System is gone');

        array_map('unlink', glob($directory.'/*') ?: []);
        rmdir($directory);
    }

    public function testAnIllegalLineIsReported(): void
    {
        $directory = sys_get_temp_dir().'/openings-'.bin2hex(random_bytes(4));
        mkdir($directory);
        foreach (OpeningSynchronizer::FILES as $file) {
            file_put_contents($directory.'/'.$file, "eco\tname\tpgn\n");
        }
        file_put_contents($directory.'/e.tsv', "eco\tname\tpgn\nE00\tBroken\t1. e5\n");

        try {
            $this->expectException(\RuntimeException::class);
            self::getContainer()->get(OpeningSynchronizer::class)->sync($directory);
        } finally {
            array_map('unlink', glob($directory.'/*') ?: []);
            rmdir($directory);
        }
    }
}
