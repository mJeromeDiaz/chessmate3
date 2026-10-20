<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coordinates;

use App\Entity\User;
use App\Enum\Coordinates\Mode;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;

/**
 * The name and colour modes of the coordinates series (docs/COORDINATES.md, § 2 bis): their
 * answers (a square named, light or dark) judged again by the server, no validation of an
 * orientation (20 XP a series, no bonus), their own lines in the overview; the mode checked.
 *
 * @phpstan-import-type RunJson from WoodpeckerWebTestCase
 */
final class CoordinatesModesTest extends WoodpeckerWebTestCase
{
    public function testAColourSeriesIsAnsweredLightOrDarkAndNeverValidates(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->start($alice, 'color');
        $item = $this->item($alice, $run['id']);
        self::assertSame('color', $item['data']['mode']);
        $squares = $item['data']['squares'];

        $this->travel('+30 seconds');
        // A square is not an answer here.
        self::assertSame(400, $this->submit($alice, $run['id'], $item['id'], [['index' => 0, 'square' => 'e4', 'ms' => 500]])->getStatusCode());
        $answers = [];
        for ($i = 0; $i < 20; ++$i) {
            $colour = Mode::colorOf($squares[$i]);
            // The fourth one wrong.
            $answers[] = ['index' => $i, 'square' => 3 === $i ? ('light' === $colour ? 'dark' : 'light') : $colour, 'ms' => 500];
        }
        $response = $this->submit($alice, $run['id'], $item['id'], $answers);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        /** @var array{result: array{data: array{answerCount: int, successCount: int}}} $answered */
        $answered = $this->json($response);
        $result = $answered['result'];
        self::assertSame([20, 19], [$result['data']['answerCount'], $result['data']['successCount']]);

        $this->travel('+40 seconds');
        $closed = $this->getRun($alice, $run['id']);
        self::assertSame(['time_up', 20, 19, 'color', false], [
            $closed['closeReason'], $closed['summary']['itemCount'] ?? null, $closed['summary']['successCount'] ?? null,
            $closed['summary']['metrics']['mode'] ?? null, $closed['summary']['metrics']['validated'] ?? null,
        ]);
        /** @var array{items: list<array{status: string, data: array{expected: string, mode: string}}>} $review */
        $review = $this->json($this->api('GET', '/api/training/runs/'.$run['id'].'/review', $alice));
        $wrong = $review['items'][3];
        self::assertSame(['fail', Mode::colorOf($squares[3]), 'color'], [$wrong['status'], $wrong['data']['expected'], $wrong['data']['mode']]);

        $this->runOutbox();
        self::assertSame(20, $this->xpOf($alice), 'A series, no validation bonus.');
        /** @var array{orientations: list<array{seriesCount: int}>, modes: list<array{mode: string, seriesCount: int, best: array{successCount: int}|null}>, history: list<array{mode: string}>} $overview */
        $overview = $this->json($this->api('GET', '/api/coordinates', $alice));
        self::assertSame([0, 0], array_column($overview['orientations'], 'seriesCount'), 'The orientations count the find mode only.');
        self::assertSame([['name', 0, null], ['color', 1, 19]], array_map(
            static fn (array $mode): array => [$mode['mode'], $mode['seriesCount'], $mode['best']['successCount'] ?? null],
            $overview['modes'],
        ));
        self::assertSame('color', $overview['history'][0]['mode']);
    }

    public function testANameSeriesIsAnsweredWithTheSquares(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->start($alice, 'name', 'black');
        $item = $this->item($alice, $run['id']);
        self::assertSame(['name', 'black'], [$item['data']['mode'], $item['data']['orientation']]);
        $squares = $item['data']['squares'];

        $this->travel('+30 seconds');
        $answers = [];
        for ($i = 0; $i < 12; ++$i) {
            $answers[] = ['index' => $i, 'square' => $squares[$i], 'ms' => 1_000];
        }
        self::assertSame(400, $this->submit($alice, $run['id'], $item['id'], [['index' => 0, 'square' => 'light', 'ms' => 500]])->getStatusCode());
        self::assertSame(200, $this->submit($alice, $run['id'], $item['id'], $answers)->getStatusCode());
        $this->travel('+40 seconds');

        $closed = $this->getRun($alice, $run['id']);
        self::assertSame([12, 12, 'name', false], [
            $closed['summary']['itemCount'] ?? null, $closed['summary']['successCount'] ?? null,
            $closed['summary']['metrics']['mode'] ?? null, $closed['summary']['metrics']['validated'] ?? null,
        ], 'Enough right answers for the find mode, yet no validation.');
        $this->runOutbox();
        self::assertSame(0, $this->validationBonuses($alice));
    }

    public function testTheModeIsChecked(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $response = $this->api('POST', '/api/training/runs', $alice, [
            'module' => 'coordinates',
            'subjectId' => $alice->getId()->toRfc4122(),
            'budgetSeconds' => 60,
            'config' => ['orientation' => 'white', 'mode' => 'guess'],
        ]);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('mode must be one of find, name, color', (string) $response->getContent());

        // Without a mode: find, as before.
        $run = $this->start($alice, null);
        self::assertSame('find', $this->item($alice, $run['id'])['data']['mode']);
    }

    /**
     * @return RunJson
     */
    private function start(User $user, ?string $mode, string $orientation = 'white'): array
    {
        $config = ['orientation' => $orientation];
        if (null !== $mode) {
            $config['mode'] = $mode;
        }
        $response = $this->api('POST', '/api/training/runs', $user, [
            'module' => 'coordinates',
            'subjectId' => $user->getId()->toRfc4122(),
            'budgetSeconds' => 60,
            'config' => $config,
        ]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        /** @var RunJson */
        return $this->json($response);
    }

    /**
     * @return array{id: string, data: array{mode: string, orientation: string, squares: list<string>}}
     */
    private function item(User $user, string $runId): array
    {
        $item = $this->json($this->api('POST', '/api/training/runs/'.$runId.'/next', $user))['item'] ?? null;
        self::assertIsArray($item);

        /** @var array{id: string, data: array{mode: string, orientation: string, squares: list<string>}} */
        return $item;
    }

    /**
     * @param list<array{index: int, square: string, ms: int}> $answers
     */
    private function submit(User $user, string $runId, string $itemId, array $answers): Response
    {
        return $this->api('POST', '/api/training/runs/'.$runId.'/submission', $user, ['itemId' => $itemId, 'answers' => $answers]);
    }

    /**
     * @return RunJson
     */
    private function getRun(User $user, string $runId): array
    {
        /** @var RunJson */
        return $this->json($this->api('GET', '/api/training/runs/'.$runId, $user));
    }

    private function validationBonuses(User $user): int
    {
        $count = $this->connection()->fetchOne("SELECT COUNT(*) FROM gamification_xp_entry WHERE user_id = ? AND kind = 'validation'", [$user->getId()->toBinary()]);

        return is_numeric($count) ? (int) $count : 0;
    }

    private function xpOf(User $user): int
    {
        $sum = $this->connection()->fetchOne('SELECT COALESCE(SUM(xp), 0) FROM gamification_xp_entry WHERE user_id = ?', [$user->getId()->toBinary()]);

        return is_numeric($sum) ? (int) $sum : 0;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
