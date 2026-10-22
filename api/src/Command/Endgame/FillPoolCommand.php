<?php

declare(strict_types=1);

namespace App\Command\Endgame;

use App\Endgame\Drawing\VariantDrawer;
use App\Endgame\Lesson\Catalogue;
use App\Endgame\Lesson\Lesson;
use App\Endgame\Pool\PositionPool;
use App\Endgame\Tablebase\Tablebase;
use App\Repertoire\Lichess\LichessUnavailableException;
use Random\Randomizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fills the endgames module's positions (docs/ENDGAMES.md, § 4): for each lesson, its textbook
 * position and its variants ({@see VariantDrawer}), then positions of its material at random, each
 * kept once the Lichess tablebase accepts it ({@see PositionPool::accepts()}), up to --per-lesson.
 * --max-calls bounds the Lichess calls of a run (answers already kept cost none): the next run
 * goes on. Run after a deployment that adds lessons, and whenever more variety is wanted.
 */
#[AsCommand(name: 'app:endgame:fill-pool', description: 'Draws and checks the positions of the endgame lessons (endgame_position)')]
final class FillPoolCommand extends Command
{
    /** Random draws tried per position wanted (many are illegal or too short). */
    private const RANDOM_TRIES = 4;

    public function __construct(
        private readonly VariantDrawer $drawer,
        private readonly PositionPool $pool,
        private readonly Tablebase $tablebase,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('lesson', null, InputOption::VALUE_REQUIRED, 'One lesson only (its key)')
            ->addOption('per-lesson', null, InputOption::VALUE_REQUIRED, 'Positions wanted per lesson', '30')
            ->addOption('max-calls', null, InputOption::VALUE_REQUIRED, 'Lichess calls at most in this run', '200');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $only = $input->getOption('lesson');
        $lessons = Catalogue::all();
        if (\is_string($only)) {
            $lesson = Catalogue::get($only);
            if (null === $lesson) {
                $io->error(\sprintf('Unknown lesson "%s".', $only));

                return Command::INVALID;
            }
            $lessons = [$lesson->key => $lesson];
        }
        $wanted = is_numeric($input->getOption('per-lesson')) ? max(1, (int) $input->getOption('per-lesson')) : 30;
        $maxCalls = is_numeric($input->getOption('max-calls')) ? max(0, (int) $input->getOption('max-calls')) : 200;
        $randomizer = new Randomizer();

        $calls = 0;
        $rows = [];
        foreach ($lessons as $lesson) {
            $added = 0;
            try {
                foreach ($this->candidates($lesson, $wanted, $randomizer) as $fen) {
                    if ($this->pool->count($lesson) >= $wanted) {
                        break;
                    }
                    if ($this->pool->has($lesson, $fen)) {
                        continue;
                    }
                    $answer = $this->tablebase->lookup($fen);
                    if (null === $answer) {
                        if ($calls >= $maxCalls) {
                            $io->warning(\sprintf('Stopped at %d Lichess calls: the next run goes on.', $calls));
                            $rows[] = [$lesson->key, $added, $this->pool->count($lesson)];

                            return $this->done($io, $rows, $calls);
                        }
                        ++$calls;
                        $answer = $this->tablebase->probe($fen);
                    }
                    if (null !== $answer && PositionPool::accepts($lesson, $answer) && $this->pool->add($lesson, $fen, $answer, $randomizer)) {
                        ++$added;
                    }
                }
            } catch (LichessUnavailableException $e) {
                $io->warning(\sprintf('Lichess unavailable (%s): the next run goes on.', $e->reason));
                $rows[] = [$lesson->key, $added, $this->pool->count($lesson)];

                return $this->done($io, $rows, $calls);
            }
            $rows[] = [$lesson->key, $added, $this->pool->count($lesson)];
        }

        return $this->done($io, $rows, $calls);
    }

    /**
     * The textbook position and its variants, then random draws for a lesson with a material.
     *
     * @return iterable<string>
     */
    private function candidates(Lesson $lesson, int $wanted, Randomizer $randomizer): iterable
    {
        yield from $this->drawer->variants($lesson);
        for ($i = 0; null !== $lesson->material && $i < $wanted * self::RANDOM_TRIES; ++$i) {
            $fen = $this->drawer->random($lesson, $randomizer);
            if (null !== $fen) {
                yield $fen;
            }
        }
    }

    /**
     * @param list<array{string, int, int}> $rows
     */
    private function done(SymfonyStyle $io, array $rows, int $calls): int
    {
        $io->table(['Lesson', 'Added', 'In pool'], $rows);
        $io->success(\sprintf('%d Lichess call(s).', $calls));

        return Command::SUCCESS;
    }
}
