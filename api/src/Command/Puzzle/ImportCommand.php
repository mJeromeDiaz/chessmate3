<?php

declare(strict_types=1);

namespace App\Command\Puzzle;

use App\Puzzle\Import\CsvReader;
use App\Puzzle\Import\CsvRow;
use App\Puzzle\Import\PuzzleWriter;
use App\Puzzle\Import\SizeEstimate;
use App\Puzzle\Import\SubsetPlan;
use App\Puzzle\Import\SubsetPlanner;
use App\Puzzle\Selection\SelectionRebuilder;
use App\Puzzle\Theme\ThemeSynchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Imports a balanced subset of a Lichess puzzle export into the catalogue's database, in plain PHP
 * (the shared host has no LOAD DATA): docs/PUZZLE_IMPORT.md.
 *
 * Two passes over the files: the first counts ({@see SubsetPlanner}), the second writes the puzzles
 * kept ({@see PuzzleWriter}). Running it again is safe: the upsert on the Lichess id never
 * duplicates nor deletes, and keeps the puzzle ids. When the catalogue already holds puzzles, those
 * the export still lists are refreshed even if the subset no longer keeps them.
 */
#[AsCommand(name: 'app:puzzle:import', description: 'Imports a balanced subset of a Lichess puzzle export into the catalogue')]
final class ImportCommand extends Command
{
    private const BATCH_SIZE = 1000;
    private const INVALID_SHOWN = 10;

    public function __construct(
        private readonly CsvReader $reader,
        private readonly PuzzleWriter $writer,
        private readonly ThemeSynchronizer $themes,
        private readonly SelectionRebuilder $rebuilder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('paths', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Export files, or directories of *.csv files')
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'Selectable puzzles to keep', '1500000')
            ->addOption('rare-theme', null, InputOption::VALUE_REQUIRED, 'Themes with fewer selectable puzzles are kept whole', '2000')
            ->addOption('max-size', null, InputOption::VALUE_REQUIRED, 'Refuses to import beyond this estimated peak size of the catalogue (MB)', '900')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count and report only, write nothing')
            ->addOption('rebuild', null, InputOption::VALUE_NONE, 'Then synchronize the themes and rebuild the selection index');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var list<string> $paths */
        $paths = $input->getArgument('paths');
        $files = CsvReader::files($paths);
        if ([] === $files) {
            $io->error('No CSV file found.');

            return Command::FAILURE;
        }
        $target = $this->intOption($input, 'target');
        $maxBytes = (int) ($this->numberOption($input, 'max-size') * 1024 * 1024);

        $io->section(\sprintf('Pass 1/2: counting %d file(s)', \count($files)));
        $planner = new SubsetPlanner();
        $invalid = $this->scan($io, $files, $planner->add(...));
        $plan = $planner->plan($target, $this->intOption($input, 'rare-theme'));
        $this->report($io, $plan, $invalid);

        $existing = $this->writer->countPuzzles();
        $peak = SizeEstimate::peakBytes(
            $existing + $plan->maxKept(),
            $this->writer->countSelectable() + $plan->maxKept(),
            $plan->themesPerPuzzle,
        );
        $io->text(\sprintf(
            'Estimated peak size of the catalogue: %s MB (limit %s MB; %s puzzles already there).',
            self::number($peak / 1048576),
            self::number($maxBytes / 1048576),
            self::number($existing),
        ));
        if ($peak > $maxBytes) {
            $io->error('Over the size limit: lower --target, or raise --max-size if the database allows it.');

            return Command::FAILURE;
        }
        if ($input->getOption('dry-run')) {
            $io->success('Dry run: nothing written.');

            return Command::SUCCESS;
        }

        $io->section('Pass 2/2: writing');
        [$kept, $refreshed] = $this->write($io, $files, $plan, $existing > 0);
        $created = $this->writer->countPuzzles() - $existing;
        $io->success(\sprintf('%d puzzle(s) kept (%d new), %d more refreshed.', $kept, $created, $refreshed));

        if (!$input->getOption('rebuild')) {
            $io->note('Then run app:puzzle:sync-themes and app:puzzle:rebuild-selection (or pass --rebuild).');

            return Command::SUCCESS;
        }
        $io->text(\sprintf('Themes synchronized (%d created). Rebuilding the selection index…', $this->themes->sync()));
        $this->rebuilder->rebuildAll();
        $io->success('Selection index rebuilt.');

        return Command::SUCCESS;
    }

    /**
     * Reads every line once, handing the valid ones over.
     *
     * @param list<string>          $files
     * @param callable(CsvRow): void $onRow
     *
     * @return array{int, list<string>} invalid lines: count, first few as "file:line reason"
     */
    private function scan(SymfonyStyle $io, array $files, callable $onRow): array
    {
        $count = 0;
        $shown = [];
        $progress = $io->createProgressBar(\count($files));
        foreach ($files as $file) {
            $onInvalid = static function (int $line, string $reason) use ($file, &$count, &$shown): void {
                ++$count;
                if (\count($shown) < self::INVALID_SHOWN) {
                    $shown[] = \sprintf('%s:%d %s', basename($file), $line, $reason);
                }
            };
            foreach ($this->reader->rows($file, $onInvalid) as $row) {
                $onRow($row);
            }
            $progress->advance();
        }
        $progress->finish();
        $io->newLine(2);

        return [$count, $shown];
    }

    /**
     * @param array{int, list<string>} $invalid
     */
    private function report(SymfonyStyle $io, SubsetPlan $plan, array $invalid): void
    {
        [$invalidCount, $examples] = $invalid;
        $io->definitionList(
            ['Valid lines' => self::number($plan->total)],
            ['Invalid lines (skipped)' => self::number($invalidCount)],
            ['Selectable (quality thresholds)' => self::number($plan->selectable)],
            ['Kept (at most)' => self::number($plan->maxKept())],
            ['Themes per selectable puzzle' => number_format($plan->themesPerPuzzle, 2)],
        );
        if ([] !== $examples) {
            $io->text('First invalid lines:');
            $io->listing($examples);
        }

        $rows = [];
        foreach ($plan->available as $band => $available) {
            $from = $band * SubsetPlanner::BAND_WIDTH;
            $rows[] = [\sprintf('%d–%d', $from, $from + SubsetPlanner::BAND_WIDTH - 1), self::number($available), self::number($plan->quotas[$band] ?? 0)];
        }
        $io->table(['Rating', 'Selectable', 'Kept'], $rows);

        if ([] !== $plan->rareThemes) {
            $io->text('Rare themes, kept whole: '.implode(', ', array_map(
                static fn (string $theme, int $count): string => "$theme ($count)",
                array_keys($plan->rareThemes),
                $plan->rareThemes,
            )));
        }
        if ([] !== $plan->unknownThemes) {
            $io->warning('Theme keys unknown to App\Puzzle\Theme\ThemeCatalog (not filterable): '.implode(', ', array_map(
                static fn (string $theme, int $count): string => "$theme ($count)",
                array_keys($plan->unknownThemes),
                $plan->unknownThemes,
            )));
        }
    }

    /**
     * Second pass: upserts the puzzles the plan keeps and, when the catalogue was not empty, the
     * other ones it already holds.
     *
     * @param list<string> $files
     *
     * @return array{int, int} puzzles kept, other puzzles refreshed
     */
    private function write(SymfonyStyle $io, array $files, SubsetPlan $plan, bool $refresh): array
    {
        $kept = 0;
        $refreshed = 0;
        $batch = [];
        /** @var array<string, CsvRow> $others */
        $others = [];

        $flushOthers = function () use (&$others, &$batch, &$refreshed): void {
            foreach (array_keys($this->writer->existing(array_map('strval', array_keys($others)))) as $lichessId) {
                $batch[] = $others[$lichessId];
                ++$refreshed;
            }
            $others = [];
        };
        $flush = function () use (&$batch): void {
            $this->writer->upsert($batch);
            $batch = [];
        };

        $this->scan($io, $files, static function (CsvRow $row) use ($plan, $refresh, &$kept, &$batch, &$others, $flush, $flushOthers): void {
            if ($plan->accepts($row)) {
                $batch[] = $row;
                ++$kept;
            } elseif ($refresh) {
                $others[$row->lichessId] = $row;
                if (\count($others) >= self::BATCH_SIZE) {
                    $flushOthers();
                }
            }
            if (\count($batch) >= self::BATCH_SIZE) {
                $flush();
            }
        });
        $flushOthers();
        $flush();

        return [$kept, $refreshed];
    }

    private static function number(int|float $value): string
    {
        return number_format($value, 0, '.', ' ');
    }

    private function intOption(InputInterface $input, string $name): int
    {
        $value = $input->getOption($name);
        if (!\is_string($value) || !ctype_digit($value)) {
            throw new \InvalidArgumentException(\sprintf('--%s expects a positive integer.', $name));
        }

        return (int) $value;
    }

    private function numberOption(InputInterface $input, string $name): float
    {
        $value = $input->getOption($name);
        if (!is_numeric($value) || (float) $value < 0) {
            throw new \InvalidArgumentException(\sprintf('--%s expects a positive number.', $name));
        }

        return (float) $value;
    }
}
