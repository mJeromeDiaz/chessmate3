<?php

declare(strict_types=1);

namespace App\State\Blindfold;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Blindfold\Puzzles;
use App\Blindfold\Puzzle\PuzzleRules;
use App\Repository\Blindfold\PuzzleAttemptRepository;
use App\Security\AuthenticatedUser;
use App\Training\Run\TimeboxRunner;

/**
 * GET /blindfold/puzzles: the current user's only. A run left behind is closed first (lazy
 * closing), so that its puzzles count.
 *
 * @phpstan-import-type Counts from Puzzles
 *
 * @implements ProviderInterface<Puzzles>
 */
final class PuzzlesProvider implements ProviderInterface
{
    public function __construct(
        private readonly PuzzleAttemptRepository $attempts,
        private readonly TimeboxRunner $runner,
        private readonly AuthenticatedUser $authenticatedUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Puzzles
    {
        $user = $this->authenticatedUser->get();
        $this->runner->closeExpired($user);

        $empty = ['played' => 0, 'solved' => 0, 'helped' => 0, 'failed' => 0];
        $view = new Puzzles();
        $view->rules = PuzzleRules::toArray();
        $view->total = $empty;
        $view->byLevel = array_fill_keys(array_keys(PuzzleRules::LEVELS), $empty);
        foreach (array_keys(PuzzleRules::LENGTHS) as $length) {
            $view->byLength[$length] = $empty;
        }
        foreach ($this->attempts->countsOf($user) as ['level' => $level, 'length' => $length, 'status' => $status, 'count' => $count]) {
            if (!\in_array($status, ['solved', 'helped', 'failed'], true)) {
                continue;
            }
            $view->total = self::add($view->total, $status, $count);
            if (isset($view->byLevel[$level])) {
                $view->byLevel[$level] = self::add($view->byLevel[$level], $status, $count);
            }
            if (isset($view->byLength[$length])) {
                $view->byLength[$length] = self::add($view->byLength[$length], $status, $count);
            }
        }

        return $view;
    }

    /**
     * @param Counts                     $counts
     * @param 'solved'|'helped'|'failed' $status
     *
     * @return Counts
     */
    private static function add(array $counts, string $status, int $count): array
    {
        $counts[$status] += $count;
        $counts['played'] += $count;

        return $counts;
    }
}
