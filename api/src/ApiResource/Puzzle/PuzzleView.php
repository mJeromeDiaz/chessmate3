<?php

declare(strict_types=1);

namespace App\ApiResource\Puzzle;

use App\Entity\Puzzle\Puzzle as PuzzleEntity;

/**
 * A puzzle as the board needs it, solution included: the client gives instant feedback on each
 * move (accepted tradeoff, docs/SECURITY.md). The server re-validates everything on submission.
 */
final class PuzzleView
{
    public string $id;
    /** Position before the opponent's first move. */
    public string $fen;
    /** @var list<string> UCI; [0] is the opponent's move, then player / opponent alternately */
    public array $moves;
    /** "white" or "black": the side to move after moves[0], i.e. the board orientation. */
    public string $playerColor;
    public int $rating;
    /** @var list<string> */
    public array $themes;
    public string $gameUrl;

    public static function from(PuzzleEntity $puzzle): self
    {
        $view = new self();
        $view->id = $puzzle->getLichessId();
        $view->fen = $puzzle->getFen();
        $view->moves = $puzzle->getMoveList();
        // The FEN's side to move plays moves[0]; the player is the other side.
        $view->playerColor = 'w' === (explode(' ', $puzzle->getFen())[1] ?? 'w') ? 'black' : 'white';
        $view->rating = $puzzle->getRating();
        $view->themes = $puzzle->getThemes();
        $view->gameUrl = $puzzle->getGameUrl();

        return $view;
    }
}
