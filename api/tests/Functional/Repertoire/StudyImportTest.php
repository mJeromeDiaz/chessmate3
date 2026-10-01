<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repertoire;

use App\Entity\AuthIdentity;
use App\Entity\User;
use App\Enum\AuthProvider;
use App\Repertoire\Lichess\StudyClient;
use App\Security\OAuth\OAuthTokenVault;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Importing a Lichess study: public anonymously, private with the user's study:read token only.
 * Lichess is never called: its answers are simulated.
 */
final class StudyImportTest extends RepertoireWebTestCase
{
    /** @var list<array{url: string, authorization: string|null}> */
    private array $calls = [];
    /** @var list<MockResponse> */
    private array $responses = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client->disableReboot();
        self::getContainer()->set('lichess_api.client', new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            /** @var array{normalized_headers?: array<string, list<string>>} $options */
            $header = $options['normalized_headers']['authorization'][0] ?? null;
            $this->calls[] = ['url' => $url, 'authorization' => null === $header ? null : substr($header, \strlen('Authorization: '))];

            return array_shift($this->responses) ?? throw new \LogicException('Unexpected call to Lichess: '.$url);
        }));
    }

    /**
     * @return iterable<string, array{string, array{string, string|null}|null}>
     */
    public static function urls(): iterable
    {
        yield 'study' => ['https://lichess.org/study/abcdEFGH', ['abcdEFGH', null]];
        yield 'chapter' => ['https://lichess.org/study/abcdEFGH/ijklMNOP', ['abcdEFGH', 'ijklMNOP']];
        yield 'no scheme, www, fragment' => ['www.lichess.org/study/abcdEFGH/ijklMNOP#moves', ['abcdEFGH', 'ijklMNOP']];
        yield 'query' => [' https://lichess.org/study/abcdEFGH?x=1 ', ['abcdEFGH', null]];
        yield 'other host' => ['https://lichess.org.evil.com/study/abcdEFGH', null];
        yield 'short id' => ['https://lichess.org/study/abcd', null];
        yield 'a game' => ['https://lichess.org/abcdEFGH', null];
    }

    /**
     * @param array{string, string|null}|null $expected
     */
    #[DataProvider('urls')]
    public function testStudyUrlsAreRecognized(string $url, ?array $expected): void
    {
        self::assertSame($expected, StudyClient::parse($url));
    }

    public function testAPublicStudyIsReadAnonymouslyAndAnalysed(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->linkLichess($alice, 'lio_scopeless', []);
        $this->responses[] = new MockResponse((string) file_get_contents(__DIR__.'/../../Fixtures/Chess/study-export.pgn'), ['response_headers' => ['content-type' => 'application/x-chess-pgn']]);

        $response = $this->api('POST', '/api/repertoires/imports', $alice, ['studyUrl' => 'https://lichess.org/study/abcdEFGH']);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $import = $this->json($response);
        self::assertSame(['analyzed', 'study', 'https://lichess.org/study/abcdEFGH', 2, 'Italian', 'white'], [$import['status'], $import['source'], $import['label'], $import['games'], $import['suggestedName'], $import['suggestedColor']]);
        self::assertCount(1, $this->calls);
        self::assertStringStartsWith('https://lichess.org/api/study/abcdEFGH.pgn?', $this->calls[0]['url']);
        parse_str((string) parse_url($this->calls[0]['url'], \PHP_URL_QUERY), $query);
        self::assertSame(['clocks' => 'false', 'comments' => 'true', 'variations' => 'true', 'orientation' => 'true'], $query);
        self::assertNull($this->calls[0]['authorization'], 'a token without study:read is not used');
    }

    public function testAPrivateStudyNeedsTheStudyReadGrant(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->responses[] = new MockResponse('Not found', ['http_code' => 404]);

        $response = $this->api('POST', '/api/repertoires/imports', $alice, ['studyUrl' => 'https://lichess.org/study/abcdEFGH/ijklMNOP']);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('study_private', (string) $response->getContent());
        self::assertStringEndsWith('/api/study/abcdEFGH/ijklMNOP.pgn?clocks=false&comments=true&variations=true&orientation=true', $this->calls[0]['url']);
    }

    public function testTheGrantedTokenReadsPrivateStudies(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->linkLichess($alice, 'lio_study', ['study:read']);
        $this->responses[] = new MockResponse("[Event \"Private\"]\n\n1. e4 e5 *", ['response_headers' => ['content-type' => 'application/x-chess-pgn']]);
        $this->responses[] = new MockResponse('Not found', ['http_code' => 404]);

        self::assertSame(201, $this->api('POST', '/api/repertoires/imports', $alice, ['studyUrl' => 'https://lichess.org/study/abcdEFGH'])->getStatusCode());
        self::assertSame('Bearer lio_study', $this->calls[0]['authorization']);

        $missing = $this->api('POST', '/api/repertoires/imports', $alice, ['studyUrl' => 'https://lichess.org/study/zzzzZZZZ']);
        self::assertSame(422, $missing->getStatusCode());
        self::assertStringContainsString('study_not_found', (string) $missing->getContent());
    }

    public function testBadInputsAndLichessOutagesAreReported(): void
    {
        $alice = $this->createUser('alice@example.com');

        $invalid = $this->api('POST', '/api/repertoires/imports', $alice, ['studyUrl' => 'https://example.com/study/abcdEFGH']);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertStringContainsString('invalid_study_url', (string) $invalid->getContent());
        self::assertSame(422, $this->api('POST', '/api/repertoires/imports', $alice, ['studyUrl' => 'https://lichess.org/study/abcdEFGH', 'pgn' => '1. e4 *'])->getStatusCode(), 'one source');
        self::assertSame(422, $this->api('POST', '/api/repertoires/imports', $alice, [])->getStatusCode());
        self::assertSame([], $this->calls);

        $this->responses[] = new MockResponse('', ['http_code' => 429]);
        $limited = $this->api('POST', '/api/repertoires/imports', $alice, ['studyUrl' => 'https://lichess.org/study/abcdEFGH']);
        self::assertSame(503, $limited->getStatusCode());
        self::assertSame('rate_limited', $limited->headers->get('X-Lichess-Unavailable'));
    }

    /**
     * @param list<string> $scopes
     */
    private function linkLichess(User $user, string $token, array $scopes): void
    {
        $identity = new AuthIdentity($user, AuthProvider::Lichess, 'alicelichess');
        self::getContainer()->get(OAuthTokenVault::class)->store($identity, $token);
        $identity->setMetadata([] === $scopes ? ['username' => 'Alice'] : ['username' => 'Alice', 'scopes' => $scopes]);
        $this->entityManager->persist($identity);
        $this->entityManager->flush();
    }
}
