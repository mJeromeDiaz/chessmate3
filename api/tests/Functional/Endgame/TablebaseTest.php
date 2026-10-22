<?php

declare(strict_types=1);

namespace App\Tests\Functional\Endgame;

use App\Endgame\Tablebase\Tablebase;
use App\Entity\Tablebase\Position;
use App\Entity\Tablebase\Probe;
use App\Enum\Endgame\Result;
use App\Repertoire\Lichess\LichessUnavailableException;
use App\Tests\Unit\Endgame\AnswerTest;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The Lichess tablebase kept in the endgame database (docs/ENDGAMES.md, § 2).
 */
final class TablebaseTest extends KernelTestCase
{
    private const KRK = '8/8/8/4k3/8/8/8/R3K3 w - - 0 1';

    /** @var list<string> */
    private array $calls = [];
    private Connection $endgame;

    protected function setUp(): void
    {
        self::bootKernel();
        Clock::set(new MockClock('2026-10-22 10:00:00', 'UTC'));
        self::getContainer()->get('repertoire.explorer_cache')->clear();
        $this->endgame = self::getContainer()->get('doctrine.dbal.endgame_connection');
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testAPositionIsAskedOnceThenReadFromTheDatabase(): void
    {
        $tablebase = $this->tablebase(new MockResponse(json_encode(AnswerTest::BODY, \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]));

        // Move counters do not make another position.
        $first = $tablebase->probe(self::KRK);
        $second = $tablebase->probe('8/8/8/4k3/8/8/8/R3K3 w - - 12 40');

        self::assertCount(1, $this->calls);
        self::assertStringContainsString('fen=8/8/8/4k3/8/8/8/R3K3%20w%20-%20-%200%201', $this->calls[0]);
        self::assertNotNull($first);
        self::assertEquals($first, $second);
        self::assertSame(Result::Win, $second?->result());
        self::assertSame(['a1a7', 'a1b1'], array_map(static fn ($move): string => $move->uci, $second->keepingMoves()));
    }

    public function testAReadKeepsTheAnswerAlive(): void
    {
        $tablebase = $this->tablebase(new MockResponse(json_encode(AnswerTest::BODY, \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]));
        $tablebase->probe(self::KRK);

        Clock::set(new MockClock('2026-12-01 10:00:00', 'UTC'));
        $tablebase->probe(self::KRK);
        self::assertSame('2026-12-01', $this->endgame->fetchOne('SELECT read_on FROM endgame_tablebase'));

        // 90 days later without a read: purged.
        self::assertSame(0, $this->prune());
        Clock::set(new MockClock('2027-03-02 10:00:00', 'UTC'));
        self::assertSame(1, $this->prune());
        self::assertSame(0, $this->rows());
    }

    public function testPositionsOutsideTheTablebaseAreNeverAsked(): void
    {
        $tablebase = $this->tablebase();

        self::assertNull($tablebase->probe('rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1'));
        self::assertNull($tablebase->probe('4k3/8/8/8/8/8/8/R3K3 w Q - 0 1'), 'Castling rights: not in the tablebase.');
        self::assertSame([], $this->calls);
    }

    public function testAnUnknownPositionIsNotKept(): void
    {
        $tablebase = $this->tablebase(new MockResponse('{"error":"not found"}', ['http_code' => 404, 'response_headers' => ['content-type' => 'application/json']]));

        self::assertNull($tablebase->probe(self::KRK));
        self::assertSame(0, $this->rows());
    }

    public function testLichessDownIsReported(): void
    {
        $tablebase = $this->tablebase(new MockResponse('', ['http_code' => 503]));

        $this->expectException(LichessUnavailableException::class);
        $tablebase->probe(self::KRK);
    }

    public function testTheAnswersLiveInTheEndgameDatabaseOnly(): void
    {
        $main = self::getContainer()->get(Connection::class)->fetchOne('SELECT DATABASE()');
        self::assertIsString($main);
        self::assertNotSame($main, $this->endgame->fetchOne('SELECT DATABASE()'));

        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.endgame_entity_manager');
        self::assertSame($manager, self::getContainer()->get(ManagerRegistry::class)->getManagerForClass(Probe::class));
        $classes = array_map(static fn ($metadata): string => $metadata->getName(), $manager->getMetadataFactory()->getAllMetadata());
        sort($classes);
        self::assertSame([Position::class, Probe::class], $classes);
    }

    private function tablebase(MockResponse ...$responses): Tablebase
    {
        $client = new MockHttpClient(function (string $method, string $url) use (&$responses): MockResponse {
            $this->calls[] = $url;
            $response = array_shift($responses);
            if (null === $response) {
                throw new \LogicException('Unexpected call: '.$url);
            }

            return $response;
        }, 'https://tablebase.lichess.ovh');
        self::getContainer()->set('lichess_tablebase.client', $client);

        return self::getContainer()->get(Tablebase::class);
    }

    private function rows(): int
    {
        $count = $this->endgame->fetchOne('SELECT COUNT(*) FROM endgame_tablebase');
        self::assertTrue(is_numeric($count));

        return (int) $count;
    }

    private function prune(): int
    {
        \assert(null !== self::$kernel);
        $tester = new CommandTester((new Application(self::$kernel))->find('app:endgame:prune-tablebase'));
        $before = $this->rows();
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        return $before - $this->rows();
    }
}
