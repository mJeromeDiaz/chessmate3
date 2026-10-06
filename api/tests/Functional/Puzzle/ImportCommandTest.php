<?php

declare(strict_types=1);

namespace App\Tests\Functional\Puzzle;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:puzzle:import` against the catalogue's test database (docs/PUZZLE_IMPORT.md), its writes
 * rolled back by DAMA. `--rebuild` is left out: its DDL would commit the test transaction.
 */
final class ImportCommandTest extends KernelTestCase
{
    private const ZO = '000Zo,4r3/1k6/pp3r2/1b2P2p/3R1p2/P1R2P2/1P4PP/6K1 w - - 0 35,e5f6 e8e1 g1f2 e1f1,1340,75,86,620,endgame mate mateIn2 short,https://lichess.org/n8Ff742v#68,';
    private const AY = '000aY,r4rk1/pp3ppp/2n1b3/q1pp2B1/8/P1Q2NP1/1PP1PP1P/2KR3R w - - 0 15,g5e7 a5c3 b2c3 c6e7,1428,79,73,505,advantage master middlegame short,https://lichess.org/iihZGl6t#28,Benoni_Defense Benoni_Defense_Benoni-Indian_Defense';
    /** Played 94 times only: below the quality thresholds, never imported on its own. */
    private const VC = '000Vc,8/8/4k1p1/2KpP2p/5PP1/8/8/8 w - - 0 53,g4h5 g6h5 f4f5 e6e5 f5f6 e5f6,1556,81,73,94,crushing endgame long pawnEndgame,https://lichess.org/l6AejDMO#104,';

    private Connection $catalog;
    private string $dir;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->catalog = self::getContainer()->get('doctrine.dbal.catalog_connection');
        $this->dir = sys_get_temp_dir().'/puzzle-import-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
        parent::tearDown();
    }

    public function testADryRunReportsAndWritesNothing(): void
    {
        $this->export('part-1.csv', [self::ZO, self::AY, self::VC, '00-Zo,broken']);

        $display = $this->import(['--dry-run' => true]);

        self::assertStringContainsString('Dry run', $display);
        self::assertMatchesRegularExpression('/Valid lines\s+3/', $display);
        self::assertMatchesRegularExpression('/Invalid lines \(skipped\)\s+1/', $display);
        self::assertMatchesRegularExpression('/Selectable \(quality thresholds\)\s+2/', $display);
        self::assertStringContainsString('part-1.csv:4', $display);
        self::assertSame(0, $this->puzzleCount());
    }

    public function testTheSelectablePuzzlesOfEveryFileAreImported(): void
    {
        $this->export('part-1.csv', [self::ZO, self::VC]);
        $this->export('part-2.csv', [self::AY]);

        $display = $this->import();

        self::assertStringContainsString('2 puzzle(s) kept (2 new), 0 more refreshed', $display);
        self::assertSame(2, $this->puzzleCount());
        $zo = $this->puzzle('000Zo');
        self::assertSame('4r3/1k6/pp3r2/1b2P2p/3R1p2/P1R2P2/1P4PP/6K1 w - - 0 35', $zo['fen']);
        self::assertSame('e5f6 e8e1 g1f2 e1f1', $zo['moves']);
        self::assertSame(['endgame', 'mate', 'mateIn2', 'short'], $this->json($zo['themes']));
        self::assertNull($zo['opening_tags']);
        self::assertEquals(1, $zo['selectable']);
        self::assertSame(['Benoni_Defense', 'Benoni_Defense_Benoni-Indian_Defense'], $this->json($this->puzzle('000aY')['opening_tags']));
    }

    public function testRunningItAgainRefreshesThePuzzlesAndKeepsTheirIds(): void
    {
        $this->export('part-1.csv', [self::ZO, self::AY]);
        $this->import();
        $before = $this->puzzle('000Zo');

        // A month later: 000Zo re-rated, and 000aY voted down below the thresholds.
        $this->export('part-1.csv', [
            str_replace(',1340,75,86,620,', ',1362,74,87,700,', self::ZO),
            str_replace(',1428,79,73,505,', ',1428,79,40,505,', self::AY),
            self::VC,
        ]);
        $display = $this->import();

        self::assertStringContainsString('1 puzzle(s) kept (0 new), 1 more refreshed', $display);
        self::assertSame(2, $this->puzzleCount());
        $after = $this->puzzle('000Zo');
        self::assertSame($before['id'], $after['id']);
        self::assertSame($before['random_key'], $after['random_key']);
        self::assertEquals(1362, $after['rating']);
        self::assertEquals(700, $after['nb_plays']);
        self::assertEquals(40, $this->puzzle('000aY')['popularity']);
    }

    public function testTheTargetKeepsTheBestPuzzles(): void
    {
        $this->export('part-1.csv', [
            self::ZO,                                                               // popularity 86
            str_replace(['000Zo', ',86,'], ['000Zp', ',90,'], self::ZO),
            str_replace(['000Zo', ',86,'], ['000Zq', ',60,'], self::ZO),
        ]);

        $this->import(['--target' => '2', '--rare-theme' => '0']);

        self::assertSame(2, $this->puzzleCount());
        $this->puzzle('000Zo');
        $this->puzzle('000Zp');
    }

    public function testAnImportOverTheSizeLimitIsRefused(): void
    {
        $this->export('part-1.csv', [self::ZO, self::AY]);

        $display = $this->import(['--max-size' => '0.0001'], Command::FAILURE);

        self::assertStringContainsString('Over the size limit', $display);
        self::assertSame(0, $this->puzzleCount());
    }

    /**
     * @param list<string> $lines
     */
    private function export(string $name, array $lines): void
    {
        file_put_contents($this->dir.'/'.$name, implode("\r\n", $lines)."\r\n");
    }

    /**
     * @param array<string, string|bool> $options
     */
    private function import(array $options = [], int $status = Command::SUCCESS): string
    {
        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('app:puzzle:import'));
        $tester->execute(['paths' => [$this->dir], ...$options]);
        self::assertSame($status, $tester->getStatusCode(), $tester->getDisplay());

        return $tester->getDisplay();
    }

    private function json(mixed $value): mixed
    {
        self::assertIsString($value);

        return json_decode($value, true);
    }

    private function puzzleCount(): int
    {
        $count = $this->catalog->fetchOne('SELECT COUNT(*) FROM puzzle');

        return is_numeric($count) ? (int) $count : -1;
    }

    /**
     * @return array<string, mixed>
     */
    private function puzzle(string $lichessId): array
    {
        $row = $this->catalog->fetchAssociative('SELECT * FROM puzzle WHERE lichess_id = ?', [$lichessId]);
        self::assertIsArray($row, "No puzzle $lichessId");

        return $row;
    }
}
