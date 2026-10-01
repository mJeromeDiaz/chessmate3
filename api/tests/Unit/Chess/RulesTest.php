<?php

declare(strict_types=1);

namespace App\Tests\Unit\Chess;

use App\Chess\InvalidPositionException;
use App\Chess\Pgn\Parser;
use App\Chess\Position\FenNormalizer;
use App\Chess\Position\PositionKey;
use App\Chess\Rules;
use App\Tests\Unit\Chess\Pgn\PgnTreeRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RulesTest extends TestCase
{
    /**
     * @return array{normalize: list<array{description: string, fen: string, normalized: string}>, invalid: list<array{description: string, fen: string}>, transpositions: list<array{description: string, a: list<string>, b: list<string>, same: bool}>}
     */
    private static function vectors(): array
    {
        /** @var array{normalize: list<array{description: string, fen: string, normalized: string}>, invalid: list<array{description: string, fen: string}>, transpositions: list<array{description: string, a: list<string>, b: list<string>, same: bool}>} $vectors */
        $vectors = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/Chess/normalization.json'), true, flags: \JSON_THROW_ON_ERROR);

        return $vectors;
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function normalizations(): iterable
    {
        foreach (self::vectors()['normalize'] as $vector) {
            yield $vector['description'] => [$vector['fen'], $vector['normalized']];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPositions(): iterable
    {
        foreach (self::vectors()['invalid'] as $vector) {
            yield $vector['description'] => [$vector['fen']];
        }
    }

    /**
     * @return iterable<string, array{list<string>, list<string>, bool}>
     */
    public static function transpositions(): iterable
    {
        foreach (self::vectors()['transpositions'] as $vector) {
            yield $vector['description'] => [$vector['a'], $vector['b'], $vector['same']];
        }
    }

    #[DataProvider('normalizations')]
    public function testNormalizedFen(string $fen, string $normalized): void
    {
        self::assertSame($normalized, (new FenNormalizer())->normalize($fen));
    }

    #[DataProvider('invalidPositions')]
    public function testImpossiblePositionsAreRejected(string $fen): void
    {
        $this->expectException(InvalidPositionException::class);

        Rules::fromFen($fen);
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    #[DataProvider('transpositions')]
    public function testTranspositionsReachTheSamePosition(array $a, array $b, bool $same): void
    {
        $keyA = self::play($a);
        $keyB = self::play($b);

        self::assertSame($same, $keyA->equals($keyB));
        self::assertSame($same, $keyA->hash === $keyB->hash);
    }

    public function testAPositionKeyIsA16ByteDigestOfTheNormalizedFen(): void
    {
        $key = (new FenNormalizer())->key(Rules::INITIAL_FEN.'   ');

        self::assertSame('rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq -', $key->fen);
        self::assertSame(16, \strlen($key->hash));
        self::assertSame(substr(hash('sha256', $key->fen), 0, 32), $key->hex());
    }

    public function testUciMovesArePlayedWithTheirSan(): void
    {
        $rules = Rules::initial();

        self::assertSame('e4', $rules->playUci('e2e4')?->san);
        self::assertSame('b', $rules->sideToMove());
        self::assertNull($rules->playUci('e2e4'), 'not black\'s pawn');
        self::assertNull($rules->playUci('e7e4'), 'illegal');
        self::assertNull($rules->playUci('nonsense'));
        self::assertSame('Nf6', $rules->playUci('g8f6')?->san);
    }

    public function testAPromotionNeedsItsPieceInUci(): void
    {
        $fen = '8/P6k/8/8/8/8/8/6K1 w - - 0 1';

        self::assertNull(Rules::fromFen($fen)->playUci('a7a8'));
        self::assertSame('a8=N', Rules::fromFen($fen)->playUci('a7a8n')?->san);
    }

    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function sanMoves(): iterable
    {
        $italian = 'r1bqkbnr/pppp1ppp/2n5/4p3/2B1P3/5N2/PPPP1PPP/RNBQK2R w KQkq - 4 4';
        // Knights on b1 and f3 can both go to d2 once the d-pawn and bishop are gone.
        $twoKnights = 'r1bqkbnr/pppp1ppp/2n5/4p3/4P3/5N2/PPP2PPP/RN1QKB1R w KQkq - 0 5';

        yield 'plain piece move' => [Rules::INITIAL_FEN, 'Nf3', 'g1f3'];
        yield 'pawn push' => [Rules::INITIAL_FEN, 'e4', 'e2e4'];
        yield 'long algebraic' => [Rules::INITIAL_FEN, 'Ng1-f3', 'g1f3'];
        yield 'pawn long algebraic' => [Rules::INITIAL_FEN, 'e2e4', 'e2e4'];
        yield 'over-disambiguated' => [Rules::INITIAL_FEN, 'Ngf3', 'g1f3'];
        yield 'needless check mark and annotation' => [Rules::INITIAL_FEN, 'Nf3+!?', 'g1f3'];
        yield 'castling with letters' => [$italian, 'O-O', 'e1g1'];
        yield 'castling with zeros' => [$italian, '0-0', 'e1g1'];
        yield 'castling as a king move' => [$italian, 'Kg1', 'e1g1'];
        yield 'capture' => ['rnbqkbnr/ppp1pppp/8/3p4/4P3/8/PPPP1PPP/RNBQKBNR w KQkq d6 0 2', 'exd5', 'e4d5'];
        yield 'en passant with suffix' => ['rnbqkbnr/ppp1p1pp/8/3pPp2/8/8/PPPP1PPP/RNBQKBNR w KQkq f6 0 3', 'exf6e.p.', 'e5f6'];
        yield 'promotion with =' => ['8/P6k/8/8/8/8/8/6K1 w - - 0 1', 'a8=Q+', 'a7a8q'];
        yield 'promotion without =' => ['8/P6k/8/8/8/8/8/6K1 w - - 0 1', 'a8Q', 'a7a8q'];
        yield 'promotion in lower case' => ['8/P6k/8/8/8/8/8/6K1 w - - 0 1', 'a8=r', 'a7a8r'];
        yield 'disambiguation needed and given' => [$twoKnights, 'Nbd2', 'b1d2'];
        yield 'illegal' => [Rules::INITIAL_FEN, 'e5', null];
        yield 'ambiguous' => [$twoKnights, 'Nd2', null];
        yield 'promotion piece missing' => ['8/P6k/8/8/8/8/8/6K1 w - - 0 1', 'a8', null];
        yield 'castling not allowed' => [Rules::INITIAL_FEN, 'O-O', null];
        yield 'null move' => [Rules::INITIAL_FEN, '--', null];
        yield 'garbage' => [Rules::INITIAL_FEN, 'Zz9', null];
    }

    #[DataProvider('sanMoves')]
    public function testLenientSan(string $fen, string $san, ?string $uci): void
    {
        $move = Rules::fromFen($fen)->playSan($san);

        self::assertSame($uci, null === $move ? null : Rules::uci($move));
    }

    public function testThePlayedSanIsTheCanonicalOne(): void
    {
        $rules = Rules::fromFen('8/P6k/8/8/8/8/8/6K1 w - - 0 1');

        self::assertSame('a8=Q', $rules->playSan('a8Q')?->san);
    }

    public function testEveryLineOfThePgnFixturesReplays(): void
    {
        $parser = new Parser();
        $replayed = 0;
        foreach (['openbook-white.pgn', 'study-export.pgn'] as $fixture) {
            foreach ($parser->parse((string) file_get_contents(__DIR__.'/../../Fixtures/Chess/'.$fixture)) as $game) {
                foreach (PgnTreeRenderer::lines($game->root) as $line) {
                    $rules = Rules::fromFen($game->startingFen() ?? Rules::INITIAL_FEN);
                    foreach ($line as $san) {
                        $move = $rules->playSan($san);
                        self::assertNotNull($move, $fixture.': '.$san);
                        self::assertSame(rtrim($san, '+#'), rtrim((string) $move->san, '+#'));
                    }
                    ++$replayed;
                }
            }
        }

        self::assertSame(3 + 5 + 2, $replayed);
    }

    /**
     * @param list<string> $moves
     */
    private static function play(array $moves): PositionKey
    {
        $rules = Rules::initial();
        foreach ($moves as $uci) {
            self::assertNotNull($rules->playUci($uci), $uci);
        }

        return PositionKey::of($rules->normalizedFen());
    }
}
