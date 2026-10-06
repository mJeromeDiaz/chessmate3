<?php

declare(strict_types=1);

namespace App\Tests\Unit\Puzzle\Import;

use App\Puzzle\Import\CsvRow;
use App\Puzzle\Import\SubsetPlan;
use App\Puzzle\Import\SubsetPlanner;
use PHPUnit\Framework\TestCase;

final class SubsetPlannerTest extends TestCase
{
    private int $sequence = 0;

    public function testATargetAboveTheSelectablePuzzlesKeepsThemAllAndNothingElse(): void
    {
        $good = [$this->row(1500, 80, 500), $this->row(2500, 50, 100)];
        $poor = [$this->row(1500, 49, 5000), $this->row(1500, 100, 99)];

        $plan = $this->plan([...$good, ...$poor], 10);

        self::assertSame(4, $plan->total);
        self::assertSame(2, $plan->selectable);
        self::assertSame($good, $this->kept($plan, [...$good, ...$poor]));
    }

    public function testEachRatingBandKeepsItsShareOfTheBestPuzzles(): void
    {
        $low = [];
        for ($popularity = 51; $popularity <= 90; ++$popularity) {
            $low[] = $this->row(1000, $popularity, 1000);       // 40 puzzles in 1000–1099
        }
        $high = [];
        for ($popularity = 51; $popularity <= 60; ++$popularity) {
            $high[] = $this->row(2800, $popularity, 1000);      // 10 puzzles in 2800–2899
        }

        $plan = $this->plan([...$low, ...$high], 25);           // half of the 50

        self::assertSame([10 => 40, 28 => 10], $plan->available);
        self::assertSame([10 => 20, 28 => 5], $plan->quotas);
        self::assertSame([...\array_slice($low, 20), ...\array_slice($high, 5)], $this->kept($plan, [...$low, ...$high]));
    }

    public function testThePlayCountBreaksTiesOnPopularity(): void
    {
        $rows = [$this->row(1500, 80, 120), $this->row(1500, 80, 50_000), $this->row(1500, 80, 1000)];

        self::assertSame([$rows[1]], $this->kept($this->plan($rows, 1), $rows));
    }

    public function testATieAtTheThresholdKeepsTheSameShareOnEveryRun(): void
    {
        $rows = [];
        for ($i = 0; $i < 1000; ++$i) {
            $rows[] = $this->row(1500, 80, 500);
        }

        $first = $this->kept($this->plan($rows, 300), $rows);
        $second = $this->kept($this->plan($rows, 300), $rows);

        self::assertSame($first, $second);
        self::assertEqualsWithDelta(300, \count($first), 60);
    }

    public function testEveryPuzzleOfARareThemeIsKept(): void
    {
        $common = [];
        for ($i = 0; $i < 20; ++$i) {
            $common[] = $this->row(1500, 90 - $i, 1000, ['fork', 'short']);  // best first
        }
        $rare = [$this->row(1500, 51, 100, ['anastasiaMate', 'short']), $this->row(1500, 52, 100, ['anastasiaMate'])];

        $plan = $this->plan([...$common, ...$rare], 5, rareThemeLimit: 3);

        self::assertSame(['anastasiaMate' => 2], $plan->rareThemes);
        self::assertSame([...\array_slice($common, 0, 5), ...$rare], $this->kept($plan, [...$common, ...$rare]));
        self::assertSame(7, $plan->maxKept());
    }

    public function testUnknownThemeKeysAreCountedApart(): void
    {
        $rows = [$this->row(1500, 90, 1000, ['fork', 'newLichessTheme']), $this->row(1500, 10, 10, ['newLichessTheme'])];

        $plan = $this->plan($rows, 10);

        self::assertSame(['newLichessTheme' => 2], $plan->unknownThemes);
        self::assertSame(1.0, $plan->themesPerPuzzle);
    }

    /**
     * @param list<CsvRow> $rows
     */
    private function plan(array $rows, int $target, int $rareThemeLimit = 0): SubsetPlan
    {
        $planner = new SubsetPlanner();
        array_map($planner->add(...), $rows);

        return $planner->plan($target, $rareThemeLimit);
    }

    /**
     * @param list<CsvRow> $rows
     *
     * @return list<CsvRow>
     */
    private function kept(SubsetPlan $plan, array $rows): array
    {
        return array_values(array_filter($rows, $plan->accepts(...)));
    }

    /**
     * @param list<string> $themes
     */
    private function row(int $rating, int $popularity, int $nbPlays, array $themes = ['short']): CsvRow
    {
        return new CsvRow(
            \sprintf('t%04d', ++$this->sequence),
            '8/8/8/8/8/8/8/K6k w - - 0 1',
            'a1a2 h1h2',
            $rating,
            75,
            $popularity,
            $nbPlays,
            $themes,
            'https://lichess.org/abcdefgh',
            null,
            null,
        );
    }
}
