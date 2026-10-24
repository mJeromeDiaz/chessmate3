<?php

declare(strict_types=1);

namespace App\Tests\Functional\Endgame;

use App\Chess\Position\FenNormalizer;
use App\Entity\User;
use App\Gamification\Xp\XpRules;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\HttpFoundation\Response;

/**
 * The endgames module in timed runs (docs/ENDGAMES.md, § 5): learn and review modes, the game
 * judged again by the server, XP by the opponent's strength, the position on screen at the end not
 * counted, the review.
 *
 * @phpstan-type EndgameItem array{id: string, type: string, data: array<string, mixed>}
 */
final class EndgameRunTest extends WoodpeckerWebTestCase
{
    use KeptAnswersTrait;

    private const START = '8/8/8/P7/8/8/5k2/7K w - - 0 1';
    private const GAME = ['a5a6', 'f2e3', 'a6a7', 'e3d4', 'a7a8q'];
    private const LEARN = ['mode' => 'learn', 'lessons' => ['square-promote'], 'elo' => 1500];

    protected function setUp(): void
    {
        parent::setUp();
        self::keep(self::START, self::winWith('a5a6', 'h1h2', 23));
        self::keep('8/8/P7/8/8/4k3/8/7K w - - 0 1', self::winWith('a6a7'));
        self::keep('8/P7/8/8/3k4/8/8/7K w - - 0 1', self::winWith('a7a8q'));
    }

    public function testLearnTheTextbookPositionThenItsVariants(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startOk($alice, self::LEARN);

        $first = $this->item($alice, $run);
        self::assertSame('endgame_position', $first['type']);
        self::assertSame(
            [self::START, 'square-promote', 'pawns', 'promote', 'win', true, true, 18, null, ['elo' => 1500, 'perfect' => false], 1],
            [$first['data']['fen'], $first['data']['lesson'], $first['data']['family'], $first['data']['goal'], $first['data']['result'], $first['data']['textbook'], $first['data']['explain'], $first['data']['budgetMoves'], $first['data']['holdMoves'], $first['data']['opponent'], $first['data']['index']],
            'Mate in 23 plies: 12 moves, 18 allowed.',
        );
        self::assertSame('learn', $first['data']['mode']);
        self::assertSame($first['id'], $this->item($alice, $run)['id'], 'A reload gets the same position.');

        $won = $this->submitOk($alice, $run, ['itemId' => $first['id'], 'moves' => self::GAME], XpRules::ENDGAME_STRONG);
        self::assertTrue($won['success']);
        self::assertSame(['won', 3, null, false], [$won['data']['status'], $won['data']['playerMoves'], $won['data']['mistakeAt'], $won['data']['engineJudged']]);

        // No variant in the pool: the textbook position again, not explained twice.
        $second = $this->item($alice, $run);
        self::assertSame([self::START, true, false, 2], [$second['data']['fen'], $second['data']['textbook'], $second['data']['explain'], $second['data']['index']]);
        $spoiled = $this->submitOk($alice, $run, ['itemId' => $second['id'], 'moves' => ['h1h2']], XpRules::ENDGAME_FAILED);
        self::assertSame(['spoiled', 0, 'a5a6'], [$spoiled['data']['status'], $spoiled['data']['mistakeAt'], $spoiled['data']['bestMove']]);

        // Unfinished, illegal: refused, the position stays to play.
        $third = $this->item($alice, $run);
        self::assertSame(400, $this->api('POST', '/api/training/runs/'.$run.'/submission', $alice, ['itemId' => $third['id'], 'moves' => ['a5a6', 'f2e3']])->getStatusCode());
        self::assertSame($third['id'], $this->item($alice, $run)['id']);
        $this->submitOk($alice, $run, ['itemId' => $third['id'], 'moves' => [], 'pass' => true], XpRules::ENDGAME_FAILED);

        // Stopped with a position on screen: not counted.
        $this->item($alice, $run);
        $this->api('POST', '/api/training/runs/'.$run.'/stop', $alice);
        $review = $this->json($this->api('GET', '/api/training/runs/'.$run.'/review', $alice));
        $items = $review['items'] ?? null;
        self::assertIsArray($items);
        self::assertSame(['ok', 'fail', 'fail'], array_column($items, 'status'));
        self::assertSame(3, $this->attemptsKept());
    }

    public function testReviewServesVariantsAndTheTopOfTheSliderIsTheTablebase(): void
    {
        $variant = '8/8/8/7P/8/8/2k5/K7 w - - 0 1';
        $key = (new FenNormalizer())->key($variant);
        self::getContainer()->get('doctrine.dbal.endgame_connection')->executeStatement(
            "INSERT INTO endgame_position (lesson_key, fen, fen_hash, dtm, textbook, random_key, created_at) VALUES ('square-promote', ?, ?, 31, 0, 5, NOW())",
            [$variant, $key->hash],
            [ParameterType::STRING, ParameterType::BINARY],
        );
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startOk($alice, ['mode' => 'review', 'themes' => ['pawns'], 'lessons' => ['square-promote'], 'elo' => 2600]);

        $item = $this->item($alice, $run);

        self::assertSame([$variant, false, false, 24, ['elo' => null, 'perfect' => true]], [$item['data']['fen'], $item['data']['textbook'], $item['data']['explain'], $item['data']['budgetMoves'], $item['data']['opponent']]);
    }

    public function testSettingsAreChecked(): void
    {
        $alice = $this->createUserIn('alice@example.com');

        foreach ([
            ['elo' => 3000] + self::LEARN,
            ['mode' => 'blitz'] + self::LEARN,
            ['mode' => 'learn', 'elo' => 1500],
            ['mode' => 'learn', 'themes' => ['dragons'], 'elo' => 1500],
            ['mode' => 'learn', 'lessons' => ['breakthrough'], 'elo' => 1500],
            ['rep' => true] + self::LEARN,
        ] as $config) {
            self::assertSame(422, $this->start($alice, $config)->getStatusCode(), (string) json_encode($config));
        }
        self::assertSame(422, $this->start($alice, self::LEARN, 60)->getStatusCode(), 'A run lasts 2, 5, 10 or 15 minutes.');
    }

    /** Positions kept: the one on screen at the stop is not. */
    private function attemptsKept(): int
    {
        $count = self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM endgame_attempt');
        self::assertTrue(is_numeric($count));

        return (int) $count;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function start(User $user, array $config, int $budgetSeconds = 300): Response
    {
        return $this->api('POST', '/api/training/runs', $user, ['module' => 'endgames', 'subjectId' => $user->getId()->toRfc4122(), 'budgetSeconds' => $budgetSeconds, 'config' => $config]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function startOk(User $user, array $config): string
    {
        $response = $this->start($user, $config);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $id = $this->json($response)['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return EndgameItem
     */
    private function item(User $user, string $runId): array
    {
        $response = $this->api('POST', '/api/training/runs/'.$runId.'/next', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $item = $this->json($response)['item'] ?? null;
        self::assertIsArray($item, 'The run served no position.');

        /** @var EndgameItem */
        return $item;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{itemId: string, success: bool, data: array<string, mixed>}
     */
    private function submitOk(User $user, string $runId, array $body, int $xp): array
    {
        $response = $this->api('POST', '/api/training/runs/'.$runId.'/submission', $user, $body);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $step = $this->json($response);
        self::assertSame($xp, $step['xp'] ?? null);
        $result = $step['result'] ?? null;
        self::assertIsArray($result);

        /** @var array{itemId: string, success: bool, data: array<string, mixed>} */
        return $result;
    }
}
