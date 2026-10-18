<?php

declare(strict_types=1);

namespace App\Repertoire\Auto;

use App\Entity\User;
use App\Repertoire\Lichess\LichessUnavailableException;
use App\Repertoire\Limits;

/**
 * One round of an exploration (docs/REPERTOIRE.md, "Repertoire from my games"): the positions of
 * the frontier asked in turn, shallowest first, the frontier read again once they are all answered
 * (the replies learnt extend the trees), until {@see Limits::$autoExplorerCallsPerRequest} real
 * Lichess calls, an empty frontier or the cap. Only real calls count: what the precomputed table
 * or the cache answers is free. At the cap the exploration is marked capped and keeps everything;
 * a line ("explore further") counts its own calls against its own cap.
 */
final readonly class ExplorationRunner
{
    public function __construct(
        private ReplySource $source,
        private Limits $limits,
    ) {
    }

    /**
     * @param callable(Exploration): list<string> $frontier the positions still to ask, given what is known
     * @param string|null                          $lineKey  a line explored on its own
     *
     * @return array{calls: int, error: LichessUnavailableException|null} the real calls made; why Lichess stopped answering
     */
    public function run(Exploration $exploration, ?User $user, callable $frontier, ?string $lineKey = null): array
    {
        $calls = 0;
        while ($calls < $this->limits->autoExplorerCallsPerRequest) {
            $positions = array_values(array_filter($frontier($exploration), static fn (string $fen): bool => !isset($exploration->replies[$fen])));
            if ([] === $positions) {
                break;
            }
            foreach ($positions as $fen) {
                if (null === $lineKey && $exploration->calls >= $exploration->budget) {
                    $exploration->capped = true;

                    return ['calls' => $calls, 'error' => null];
                }
                if (null !== $lineKey && ($exploration->lines[$lineKey] ?? 0) >= $this->limits->autoMaxExplorerCalls) {
                    return ['calls' => $calls, 'error' => null];
                }
                if ($calls >= $this->limits->autoExplorerCallsPerRequest) {
                    return ['calls' => $calls, 'error' => null];
                }
                try {
                    $exploration->replies[$fen] = $this->source->replies($user, $fen, $fetched, $exploration->rule->preset);
                } catch (LichessUnavailableException $e) {
                    return ['calls' => $calls, 'error' => $e];
                }
                if ($fetched) {
                    ++$calls;
                    if (null === $lineKey) {
                        ++$exploration->calls;
                    } else {
                        $exploration->lines[$lineKey] = ($exploration->lines[$lineKey] ?? 0) + 1;
                    }
                }
            }
        }

        return ['calls' => $calls, 'error' => null];
    }
}
