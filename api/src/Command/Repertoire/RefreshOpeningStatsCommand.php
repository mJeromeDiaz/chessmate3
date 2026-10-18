<?php

declare(strict_types=1);

namespace App\Command\Repertoire;

use App\Chess\Rules;
use App\Repertoire\Auto\OpeningStatsReader;
use App\Repertoire\Auto\ReplyRule;
use App\Repertoire\Lichess\ExplorerClient;
use App\Repertoire\Lichess\LichessUnavailableException;
use App\Repertoire\Limits;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Precomputes the Lichess explorer's replies of the first plies for the analyses' preset
 * (repertoire_opening_stats, docs/REPERTOIRE.md, "Repertoire from my games"): from the initial
 * position, every popular move of both sides ({@see ReplyRule}), down to --plies. Only the rows
 * missing or older than Limits::$openingStatsTtlDays are asked again; --max-calls bounds the
 * Lichess calls of a run (the next run goes on). Daily cron (bin/cron-daily.php on the OVH host).
 */
#[AsCommand(name: 'app:repertoire:refresh-opening-stats', description: 'Precomputes the explorer replies of the first plies (repertoire_opening_stats)')]
final class RefreshOpeningStatsCommand extends Command
{
    public function __construct(
        private readonly OpeningStatsReader $reader,
        private readonly ExplorerClient $explorer,
        private readonly Limits $limits,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('plies', null, InputOption::VALUE_REQUIRED, 'Plies from the initial position', (string) $this->limits->openingStatsPlies)
            ->addOption('max-calls', null, InputOption::VALUE_REQUIRED, 'Lichess calls at most in this run', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $plies = $input->getOption('plies');
        $plies = is_numeric($plies) ? max(1, (int) $plies) : $this->limits->openingStatsPlies;
        $maxCalls = $input->getOption('max-calls');
        $maxCalls = is_numeric($maxCalls) ? max(0, (int) $maxCalls) : 100;
        $rule = ReplyRule::fromLimits($this->limits);

        $calls = $fresh = $refreshed = 0;
        $seen = [];
        $level = [Rules::initial()->normalizedFen()];
        for ($depth = 0; $depth < $plies && [] !== $level; ++$depth) {
            $next = [];
            foreach ($level as $fen) {
                if (isset($seen[$fen])) {
                    continue;
                }
                $seen[$fen] = true;
                $stored = $this->reader->stored($fen);
                if (null !== $stored && !$stored['stale']) {
                    $replies = $stored['replies'];
                    ++$fresh;
                } else {
                    if ($calls >= $maxCalls) {
                        $io->warning(\sprintf('Stopped at %d Lichess calls: the next run goes on.', $calls));

                        return $this->done($io, $fresh, $refreshed, $calls);
                    }
                    try {
                        $replies = ReplyRule::compact($this->explorer->explore(null, OpeningStatsReader::query($fen), $fetched));
                    } catch (LichessUnavailableException $e) {
                        $io->warning(\sprintf('Lichess unavailable (%s): the next run goes on.', $e->reason));

                        return $this->done($io, $fresh, $refreshed, $calls);
                    }
                    if ($fetched) {
                        ++$calls;
                    }
                    $this->reader->store($fen, $replies);
                    ++$refreshed;
                }
                foreach ($rule->popular($replies) as $move) {
                    $rules = Rules::fromFen($fen);
                    if (null !== $rules->playUci($move['uci'])) {
                        $next[] = $rules->normalizedFen();
                    }
                }
            }
            $level = $next;
        }

        return $this->done($io, $fresh, $refreshed, $calls);
    }

    private function done(SymfonyStyle $io, int $fresh, int $refreshed, int $calls): int
    {
        $io->success(\sprintf('Opening stats: %d fresh, %d written (%d Lichess calls).', $fresh, $refreshed, $calls));

        return Command::SUCCESS;
    }
}
