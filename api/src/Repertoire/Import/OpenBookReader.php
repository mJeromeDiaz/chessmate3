<?php

declare(strict_types=1);

namespace App\Repertoire\Import;

use App\Chess\InvalidPositionException;
use App\Chess\Rules;
use App\Repertoire\Limits;

/**
 * Reads an OpenBook backup (openbookchess.com, JSON; docs/REPERTOIRE.md, "OpenBook") into one
 * {@see ImportedTree} per side:
 *
 *     {"version": 1, "date": "...", "user": "...",
 *      "repertoire": {"white": {"<FEN>": {"moves": ["e4"], "notes": ""}, ...}, "black": {...}},
 *      "srs": {...}, "sets": [...]}
 *
 * Only the positions where the side is to move are in the file, with the prepared move(s) and
 * notes; the opponent's moves are not. They are found back by walking from the initial position:
 * where the opponent is to move, every legal move reaching a position of the file is a reply.
 * FEN keys are compared normalized (their en passant square is set after any double push). The
 * notes become the comment of the position's first move. "srs" and "sets" are not imported.
 */
final readonly class OpenBookReader
{
    public const SOURCE = 'openbook';

    public function __construct(
        private Limits $limits,
    ) {
    }

    /**
     * Whether the text is an OpenBook backup (a PGN file can start with a "{" comment, so the
     * JSON itself is checked).
     */
    public static function recognizes(string $text): bool
    {
        $text = ltrim($text, "\u{FEFF} \t\n\r");
        if (!str_starts_with($text, '{')) {
            return false;
        }
        try {
            $data = json_decode($text, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        return \is_array($data) && \is_array($data['repertoire'] ?? null);
    }

    /**
     * @param (callable(int, int): void)|null $progress told the positions walked and the total
     *
     * @return array{ImportedTree, ImportedTree, 'white'|'black'} white, black, the side to suggest
     *                                                            (the one with positions, White if both)
     *
     * @throws ImportRejectedException
     */
    public function read(string $json, ?callable $progress = null): array
    {
        if (\strlen($json) > $this->limits->maxImportBytes) {
            throw new ImportRejectedException(ImportRejectedException::TOO_LARGE, sprintf('%d bytes at most.', $this->limits->maxImportBytes));
        }
        try {
            $data = json_decode(ltrim($json, "\u{FEFF}"), true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ImportRejectedException(ImportRejectedException::SYNTAX, $e->getMessage());
        }
        $repertoire = \is_array($data) ? ($data['repertoire'] ?? null) : null;
        if (!\is_array($repertoire) || 1 !== ($data['version'] ?? null)) {
            throw new ImportRejectedException(ImportRejectedException::SYNTAX, 'Not an OpenBook backup (version 1).');
        }
        $white = \is_array($repertoire['white'] ?? null) ? $repertoire['white'] : [];
        $black = \is_array($repertoire['black'] ?? null) ? $repertoire['black'] : [];
        if (\count($white) + \count($black) > $this->limits->maxPositions) {
            throw new ImportRejectedException(ImportRejectedException::TOO_MANY_POSITIONS, sprintf('%d positions at most.', $this->limits->maxPositions));
        }

        $total = \count($white) + \count($black);
        $trees = [];
        $done = 0;
        foreach (['white' => $white, 'black' => $black] as $side => $entries) {
            $trees[$side] = $this->side($side, $entries, null === $progress ? null : static function (int $walked) use ($progress, &$done, $total): void {
                $progress($done + $walked, $total);
            });
            $done += \count($entries);
        }
        if ([] === $trees['white']->edges && [] === $trees['black']->edges) {
            throw new ImportRejectedException(ImportRejectedException::EMPTY, 'No legal move to import.');
        }
        $suggested = [] === $trees['white']->edges ? 'black' : 'white';

        return [$trees['white'], $trees['black'], $suggested];
    }

    /**
     * @param 'white'|'black'               $side
     * @param array<mixed>                  $entries FEN => {moves, notes}
     * @param (callable(int): void)|null    $progress
     */
    private function side(string $side, array $entries, ?callable $progress): ImportedTree
    {
        $tree = new ImportedTree();
        $tree->games = 1;
        $tree->color = $side;
        $tree->name = 'OpenBook';
        $turn = 'white' === $side ? 'w' : 'b';

        /** @var array<string, array{moves: list<string>, notes: string}> $prepared normalized FEN => entry */
        $prepared = [];
        foreach ($entries as $fen => $entry) {
            $moves = \is_array($entry) && \is_array($entry['moves'] ?? null) ? array_values(array_filter($entry['moves'], 'is_string')) : [];
            try {
                $rules = Rules::fromFen(trim((string) $fen).' 0 1');
            } catch (InvalidPositionException) {
                $tree->warn(ImportedTree::INVALID_POSITION, 1, (string) $fen);
                continue;
            }
            if ($rules->sideToMove() !== $turn) {
                $tree->warn(ImportedTree::INVALID_POSITION, 1, (string) $fen);
                continue;
            }
            $key = $rules->normalizedFen();
            $notes = \is_array($entry) && \is_string($entry['notes'] ?? null) ? $entry['notes'] : '';
            $prepared[$key] = [
                'moves' => array_values(array_unique([...$prepared[$key]['moves'] ?? [], ...$moves])),
                'notes' => '' !== ($prepared[$key]['notes'] ?? '') ? $prepared[$key]['notes'] : $notes,
            ];
        }

        $initial = Rules::initial()->normalizedFen();
        $tree->positions[$initial] = 'w';
        $depths = [$initial => 0];
        $queue = [$initial];
        $used = [];
        $tooDeep = false;
        for ($head = 0; $head < \count($queue); ++$head) {
            $fen = $queue[$head];
            $depth = $depths[$fen];
            $rules = Rules::fromFen($fen.' 0 1');
            if ($rules->sideToMove() === $turn) {
                if (!isset($prepared[$fen])) {
                    continue;
                }
                $used[$fen] = true;
                if (null !== $progress) {
                    $progress(\count($used));
                }
                if ($depth + 1 > $this->limits->maxDepth) {
                    if (!$tooDeep) {
                        $tree->warn(ImportedTree::TOO_DEEP, 1, self::label($depth, $prepared[$fen]['moves'][0] ?? ''));
                        $tooDeep = true;
                    }
                    continue;
                }
                foreach ($prepared[$fen]['moves'] as $i => $san) {
                    $position = 0 === $i ? $rules : Rules::fromFen($fen.' 0 1');
                    $move = $position->playSan($san);
                    if (null === $move) {
                        $tree->warn(ImportedTree::ILLEGAL_MOVE, 1, self::label($depth, $san));
                        continue;
                    }
                    [$comment, $truncated] = 0 === $i ? PgnAnalyzer::plainText($prepared[$fen]['notes']) : [null, false];
                    if ($truncated) {
                        $tree->warn(ImportedTree::COMMENT_TRUNCATED, 1, self::label($depth, (string) $move->san));
                    }
                    $to = $position->normalizedFen();
                    $tree->addEdge($fen, $to, $position->sideToMove(), Rules::uci($move), (string) $move->san, $comment, [], 1);
                    $this->visit($to, $depth + 1, $queue, $depths);
                }
                continue;
            }
            if ($depth + 1 > $this->limits->maxDepth) {
                continue;
            }
            foreach ($rules->legalMoves() as $candidate) {
                $move = $rules->playUci(Rules::uci($candidate));
                if (null === $move) {
                    continue;
                }
                $to = $rules->normalizedFen();
                $rules->undo();
                if (isset($prepared[$to])) {
                    $tree->addEdge($fen, $to, $turn, Rules::uci($move), (string) $move->san, null, [], 1);
                    $this->visit($to, $depth + 1, $queue, $depths);
                }
            }
        }

        $unreachable = \count(array_diff_key($prepared, $used));
        if ($unreachable > 0) {
            $tree->warn(ImportedTree::UNREACHABLE, 1, null, $unreachable);
        }

        return $tree;
    }

    /**
     * @param list<string>       $queue
     * @param array<string, int> $depths
     */
    private function visit(string $fen, int $depth, array &$queue, array &$depths): void
    {
        if (!isset($depths[$fen])) {
            $depths[$fen] = $depth;
            $queue[] = $fen;
        }
    }

    /** "3.Bf4" or "7...h5". */
    private static function label(int $ply, string $san): string
    {
        return (intdiv($ply, 2) + 1).(0 === $ply % 2 ? '.' : '...').$san;
    }
}
