<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repertoire\Import;

use App\Chess\Rules;
use App\Enum\Repertoire\Color;
use App\Repertoire\Import\ImportedTree;
use App\Repertoire\Import\ImportPlan;
use App\Repertoire\Import\ImportPlanner;
use App\Repertoire\Import\PgnAnalyzer;
use App\Repertoire\Import\Target;
use App\Repertoire\Limits;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type TargetMove from Target
 */
final class ImportPlannerTest extends TestCase
{
    public function testTheOpenBookRepertoireGivesThreeLinesWithoutConflict(): void
    {
        $plan = $this->plan((string) file_get_contents(__DIR__.'/../../../Fixtures/Chess/openbook-white.pgn'), Color::White);

        self::assertSame(3, $plan->lines);
        self::assertSame(40, $plan->filePositions);
        self::assertSame(39, $plan->newPositions);
        self::assertSame(39, $plan->newMoves);
        self::assertSame(40, $plan->positionsAfter);
        self::assertSame([], $plan->conflicts);
        self::assertSame([], $plan->warnings);
        self::assertCount(39, $plan->positions);
        $roles = array_count_values(array_column($plan->moves, 'role'));
        self::assertSame(['reference' => 20, 'reply' => 19], $roles, 'White moves are the references');
        $c6 = $this->move($plan, 'Nc6');
        $e6 = $this->move($plan, 'e6', 'Bf4');
        self::assertSame([0, 1], [$c6['sortOrder'], $e6['sortOrder']], 'the main line first');
    }

    public function testSeveralUserMovesInTheFileAreAConflictAndOnlyTheChoiceIsImported(): void
    {
        $pgn = '1. d4 (1. e4 e5) 1... d5 *';
        $plan = $this->plan($pgn, Color::White);

        self::assertSame([[
            'fen' => self::INITIAL(),
            'path' => [],
            'candidates' => [
                ['uci' => 'd2d4', 'san' => 'd4', 'origin' => 'file'],
                ['uci' => 'e2e4', 'san' => 'e4', 'origin' => 'file'],
            ],
            'choice' => 'd2d4',
        ]], $plan->conflicts);
        self::assertSame(['d4', 'd5'], array_column($plan->moves, 'san'), 'the variation of the user\'s move is not imported');
        self::assertSame(['reference', 0], [$this->move($plan, 'd4')['role'], $this->move($plan, 'd4')['sortOrder']]);
        self::assertSame(1, $plan->lines);

        $chosen = $this->plan($pgn, Color::White, choices: [self::INITIAL() => 'e2e4']);
        self::assertSame('e2e4', $chosen->conflicts[0]['choice']);
        self::assertSame(['e4', 'e5'], array_column($chosen->moves, 'san'));

        // For Black, those are opponent moves: no conflict, both lines.
        $black = $this->plan($pgn, Color::Black);
        self::assertSame([], $black->conflicts);
        self::assertSame(['reply', 0], [$this->move($black, 'd4')['role'], $this->move($black, 'd4')['sortOrder']]);
        self::assertSame(['reply', 1], [$this->move($black, 'e4')['role'], $this->move($black, 'e4')['sortOrder']]);
        self::assertSame('reference', $this->move($black, 'd5')['role']);
    }

    public function testMergingKeepsWhatExistsOrReplacesItWhenTheFileIsChosen(): void
    {
        $target = $this->target([
            ['e4', null, 'e4', 'reference', 0, null, []],
            ['e4 e5', 'e4', 'e5', 'reply', 0, 'Open game', [1]],
        ]);
        $pgn = '1. d4 {Closed} (1. e4 $1 {Best by test} e5 {Theirs} $2 2. Nf3) 1... d5 *';

        $plan = $this->plan($pgn, Color::White, $target);
        self::assertSame([['e2e4', 'both'], ['d2d4', 'file']], array_map(static fn (array $c): array => [$c['uci'], $c['origin']], $plan->conflicts[0]['candidates']));
        self::assertSame('e2e4', $plan->conflicts[0]['choice'], 'the existing move by default');
        self::assertSame(['Nf3'], array_column($plan->moves, 'san'), '1.d4 is not imported');
        self::assertSame([], $plan->replaced);
        self::assertSame(['id-e4' => ['comment' => 'Best by test', 'nags' => [1]]], $plan->fills, 'e5 keeps its own annotations');
        self::assertSame(2, $plan->knownMoves);
        self::assertSame(4, $plan->positionsAfter);

        $replaced = $this->plan($pgn, Color::White, $target, [self::INITIAL() => 'd2d4']);
        self::assertSame(['id-e4'], $replaced->replaced);
        self::assertSame(['d4', 'd5'], array_column($replaced->moves, 'san'));
        self::assertSame([], $replaced->fills);
        self::assertSame(2, $replaced->trashedPositions, '1.e4 and 1...e5 go to the trash');
        self::assertSame(3, $replaced->positionsAfter);
    }

    public function testConflictsShowTheirPathAndIgnoreUnknownChoices(): void
    {
        $plan = $this->plan('1. e4 e5 2. Nf3 (2. Bc4) *', Color::White, choices: ['nope' => 'e2e4']);

        $after = $this->fenAfter('e4 e5');
        self::assertSame($after, $plan->conflicts[0]['fen']);
        self::assertSame(['e4', 'e5'], $plan->conflicts[0]['path']);
        $wrong = $this->plan('1. e4 e5 2. Nf3 (2. Bc4) *', Color::White, choices: [$after => 'a2a3']);
        self::assertSame('g1f3', $wrong->conflicts[0]['choice']);
    }

    public function testAChapterStartingElsewhereNeedsItsPosition(): void
    {
        $chapter = sprintf("[FEN \"%s 0 2\"]\n\n2. Nf3 Nc6 *", $this->fenAfter('e4 e5'));

        $alone = $this->plan($chapter, Color::White);
        self::assertSame([['type' => ImportedTree::START_NOT_FOUND, 'game' => 1]], $alone->warnings);
        self::assertSame(0, $alone->newMoves);

        $withFirst = $this->plan("1. e4 e5 *\n\n".$chapter, Color::White);
        self::assertSame([], $withFirst->warnings);
        self::assertSame(4, $withFirst->newMoves);
        self::assertSame(1, $withFirst->lines);

        $inTarget = $this->plan($chapter, Color::White, $this->target([
            ['e4', null, 'e4', 'reference', 0, null, []],
            ['e4 e5', 'e4', 'e5', 'reply', 0, null, []],
        ]));
        self::assertSame([], $inTarget->warnings);
        self::assertSame(2, $inTarget->newMoves);
    }

    public function testAMoveClosingACycleWithTheTargetIsLeftOut(): void
    {
        $target = $this->target([
            ['Nf3', null, 'Nf3', 'reference', 0, null, []],
            ['Nf3 Nf6', 'Nf3', 'Nf6', 'reply', 0, null, []],
            ['Nf3 Nf6 Ng1', 'Nf3 Nf6', 'Ng1', 'reference', 0, null, []],
        ]);
        $chapter = sprintf("[FEN \"%s 3 3\"]\n\n2... Ng8 3. e4 *", $this->fenAfter('Nf3 Nf6 Ng1'));

        $plan = $this->plan($chapter, Color::White, $target);

        self::assertSame([['type' => ImportedTree::REPEATED_POSITION, 'game' => 1, 'move' => 'Ng8']], $plan->warnings);
        // 3.e4 is played from the initial position (where 2...Ng8 would have gone back to), which
        // prepares 1.Nf3: a conflict, the prepared move kept by default.
        self::assertSame(0, $plan->newMoves);
        self::assertSame(['g1f3', 'e2e4'], array_column($plan->conflicts[0]['candidates'], 'uci'));
    }

    /**
     * @param array<string, string> $choices
     */
    private function plan(string $pgn, Color $color, ?Target $target = null, array $choices = []): ImportPlan
    {
        $tree = (new PgnAnalyzer(new Limits()))->analyze($pgn);

        return (new ImportPlanner())->plan($tree, $color, $target ?? Target::empty(), $choices);
    }

    /**
     * A target from moves given as [path id, from path, SAN, role, sort order, comment, NAGs]
     * (paths are SAN from the initial position; move ids are "id-" + SAN).
     *
     * @param list<array{string, string|null, string, string, int, string|null, list<int>}> $rows
     */
    private function target(array $rows): Target
    {
        $positions = [self::INITIAL() => 'root'];
        $moves = [];
        foreach ($rows as [$path, $fromPath, $san, $role, $order, $comment, $nags]) {
            $from = null === $fromPath ? self::INITIAL() : $this->fenAfter($fromPath);
            $to = $this->fenAfter($path);
            $positions[$to] = 'p-'.$path;
            $rules = Rules::fromFen($from);
            $uci = Rules::uci($rules->playSan($san) ?? throw new \LogicException($san));
            $moves[] = ['id' => 'id-'.$san, 'from' => $from, 'to' => $to, 'uci' => $uci, 'san' => $san, 'role' => $role, 'sortOrder' => $order, 'comment' => $comment, 'nags' => $nags];
        }

        return new Target($positions, $moves);
    }

    private function fenAfter(string $sans): string
    {
        $rules = Rules::initial();
        foreach (explode(' ', $sans) as $san) {
            $rules->playSan($san) ?? throw new \LogicException($san);
        }

        return $rules->normalizedFen();
    }

    /**
     * @return array{from: string, to: string, uci: string, san: string, role: string, sortOrder: int, comment: string|null, nags: list<int>}
     */
    private function move(ImportPlan $plan, string $san, ?string $after = null): array
    {
        foreach ($plan->moves as $move) {
            if ($move['san'] === $san) {
                return $move;
            }
        }
        throw new \LogicException('No new move '.$san.($after ? ' after '.$after : ''));
    }

    private static function INITIAL(): string
    {
        return Rules::initial()->normalizedFen();
    }
}
