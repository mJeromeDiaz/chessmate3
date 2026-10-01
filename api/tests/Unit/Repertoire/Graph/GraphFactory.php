<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repertoire\Graph;

use App\Chess\Pgn\Node;
use App\Chess\Pgn\Parser;
use App\Chess\Rules;
use App\Enum\Repertoire\MoveRole;
use App\Repertoire\Graph\Graph;
use App\Repertoire\Graph\GraphMove;
use App\Repertoire\Graph\GraphPosition;

/**
 * Builds in-memory graphs the way the editor does, move by move: positions keyed by normalized
 * FEN (ids are the FENs), move ids in creation order, one user move per position (a second one is
 * refused), the first move into a position canonical.
 */
final class GraphFactory
{
    private Graph $graph;
    private int $sequence = 0;
    /** @var array<string, string> "fromFen san" => move id */
    private array $ids = [];

    public function __construct(string $color = 'white')
    {
        $root = Rules::initial()->normalizedFen();
        $this->graph = new Graph($root, 'white' === $color ? 'w' : 'b');
        $this->graph->addPosition(new GraphPosition($root, 'w'));
    }

    public static function fromPgn(string $pgn, string $color = 'white'): self
    {
        $factory = new self($color);
        foreach ((new Parser())->parse($pgn) as $game) {
            $factory->tree($game->root, Rules::initial()->normalizedFen());
        }

        return $factory;
    }

    public function graph(): Graph
    {
        return $this->graph;
    }

    /**
     * Plays a line of SAN moves from the initial position, adding what is missing.
     */
    public function line(string $sans): self
    {
        $fen = $this->graph->rootId;
        foreach (explode(' ', $sans) as $san) {
            $fen = $this->add($fen, $san);
        }

        return $this;
    }

    /** The id of the move $san played after the SAN moves $path from the initial position. */
    public function moveId(string $path, string $san): string
    {
        $fen = $this->graph->rootId;
        foreach ('' === $path ? [] : explode(' ', $path) as $step) {
            $fen = $this->graph->move($this->ids[$fen.' '.$step])->to ?? throw new \LogicException($step);
        }

        return $this->ids[$fen.' '.$san] ?? throw new \LogicException($san);
    }

    private function tree(Node $node, string $fen): void
    {
        foreach ($node->children as $child) {
            $this->tree($child, $this->add($fen, $child->san));
        }
    }

    /** @return string the FEN reached */
    private function add(string $fen, string $san): string
    {
        if (isset($this->ids[$fen.' '.$san])) {
            return $this->graph->move($this->ids[$fen.' '.$san])->to ?? '';
        }
        $rules = Rules::fromFen($fen);
        $rules->playSan($san) ?? throw new \LogicException('Illegal '.$san);
        $to = $rules->normalizedFen();
        $isNew = null === $this->graph->position($to);
        if ($isNew) {
            $this->graph->addPosition(new GraphPosition($to, $rules->sideToMove()));
        }
        $siblings = $this->graph->outgoing($fen);
        $user = $this->graph->isUserTurn($fen);
        if ($user && [] !== $siblings) {
            throw new \LogicException('One prepared move per position: '.$san.' after '.$fen);
        }
        $role = $user ? MoveRole::Reference : MoveRole::Reply;
        $id = sprintf('m%05d', ++$this->sequence);
        $this->graph->addMove(new GraphMove($id, $fen, $to, $role, \count($siblings), $isNew, null, $san));
        $this->ids[$fen.' '.$san] = $id;

        return $to;
    }
}
