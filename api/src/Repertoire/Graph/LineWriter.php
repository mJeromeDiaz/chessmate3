<?php

declare(strict_types=1);

namespace App\Repertoire\Graph;

use App\Chess\Rules;
use App\Entity\Repertoire\Repertoire;
use App\Entity\User;
use App\Enum\Repertoire\TrashReason;
use App\Repertoire\Exception\IllegalMoveException;
use App\Repertoire\Exception\LimitReachedException;
use App\Repertoire\Exception\PositionNotFoundException;
use App\Repertoire\Exception\PositionOccupiedException;
use App\Repertoire\Exception\RepeatedPositionException;
use App\Repertoire\Exception\RepertoireNotFoundException;
use App\Repertoire\Exception\StaleVersionException;
use App\Repertoire\Import\ImportedTree;
use App\Repertoire\Import\ImportPlan;
use App\Repertoire\Import\ImportPlanner;
use App\Repertoire\Import\Target;
use App\Repository\Repertoire\PositionRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Saves a line drafted in the editor (docs/REPERTOIRE.md, "Brouillon"): the moves played from a
 * position of the repertoire, in one change through the import path (one entry of the undo
 * journal). The moves already there are joined; where the user is to move, the line's move is the
 * prepared one: when another one is prepared there, the line is refused unless $replace, which
 * puts the former move and its suite in the trash.
 */
final readonly class LineWriter
{
    public function __construct(
        private GraphEditor $editor,
        private ImportPlanner $planner,
        private PositionRepository $positions,
        private Connection $connection,
    ) {
    }

    /**
     * Also refused, from inside the change (before its first write): PositionNotFoundException,
     * IllegalMoveException, RepeatedPositionException, and PositionOccupiedException when another
     * move is prepared on the way and not $replace.
     *
     * @param list<string> $ucis
     *
     * @throws RepertoireNotFoundException
     * @throws LimitReachedException
     * @throws StaleVersionException
     */
    public function save(User $user, Uuid $repertoireId, Uuid $fromPositionId, array $ucis, bool $replace, ?int $baseVersion = null): Change
    {
        return $this->editor->import($user, $repertoireId, function (Repertoire $repertoire) use ($fromPositionId, $ucis, $replace): ImportPlan {
            $from = $this->positions->findInRepertoire($fromPositionId, $repertoire) ?? throw new PositionNotFoundException();
            [$tree, $choices] = self::line($from->getFen(), $ucis, $repertoire->getColor()->turn());
            $plan = $this->planner->plan($tree, $repertoire->getColor(), Target::load($this->connection, $repertoire), $choices);
            foreach ($plan->warnings as $warning) {
                if (ImportedTree::REPEATED_POSITION === $warning['type']) {
                    throw new RepeatedPositionException();
                }
            }
            if (!$replace && [] !== $plan->replaced) {
                throw new PositionOccupiedException($plan->replaced[0]);
            }

            return $plan;
        }, $baseVersion, TrashReason::Replaced);
    }

    /**
     * The line as an imported tree, with the line's move chosen where the user is to move.
     *
     * @param list<string> $ucis
     *
     * @return array{ImportedTree, array<string, string>}
     *
     * @throws IllegalMoveException
     * @throws RepeatedPositionException a position the line already went through
     */
    public static function line(string $fromFen, array $ucis, string $userTurn): array
    {
        $rules = Rules::fromFen($fromFen);
        $from = $rules->normalizedFen();
        $tree = new ImportedTree();
        $tree->positions[$from] = $rules->sideToMove();
        $tree->starts[] = ['game' => 1, 'fen' => $from];
        $tree->games = 1;
        $seen = [$from => true];
        $choices = [];
        foreach ($ucis as $uci) {
            $turn = $rules->sideToMove();
            $move = $rules->playUci($uci) ?? throw new IllegalMoveException();
            $to = $rules->normalizedFen();
            if (isset($seen[$to])) {
                throw new RepeatedPositionException();
            }
            $seen[$to] = true;
            $tree->addEdge($from, $to, $rules->sideToMove(), Rules::uci($move), (string) $move->san, null, [], 1);
            if ($turn === $userTurn) {
                $choices[$from] = Rules::uci($move);
            }
            $from = $to;
        }

        return [$tree, $choices];
    }
}
