<?php

declare(strict_types=1);

namespace App\Tests\Functional\Profile;

use App\Activity\Event\ExerciseCompleted;
use App\Entity\Activity\LogEntry;
use App\Entity\AuthIdentity;
use App\Entity\Puzzle\Attempt;
use App\Entity\Puzzle\Puzzle;
use App\Entity\Training\Session;
use App\Entity\User;
use App\Enum\Activity\ExerciseType;
use App\Enum\AuthProvider;
use App\Enum\Repertoire\Color;
use App\Enum\Training\Module;
use App\Repertoire\RepertoireManager;
use App\Security\OAuth\OAuthTokenVault;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /api/profile/export (docs/AUTH.md): the user's data as a ZIP of JSON and PGN files, theirs
 * only, without any secret; allowed to a frozen account; 3 a day.
 */
final class ExportTest extends WebTestCase
{
    private const LICHESS_TOKEN = 'lichess-secret-token';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::getContainer()->get('cache.rate_limiter')->clear();
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function testTheZipHoldsTheUsersDataOnlyAndNoSecret(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $puzzle = new Puzzle('Ab1Cd', '8/8/8/8/8/8/8/K6k w - - 0 1', 'a1a2 h1h2', 1500, 80, 90, 1000, ['fork', 'short'], 'https://lichess.org/test');
        $this->entityManager->persist($puzzle);
        foreach ([$alice, $bob] as $user) {
            $attempt = new Attempt($user, $puzzle, true, new \DateTimeImmutable('-1 hour'));
            $attempt->resolve(true, ['h1h2'], 0, 0, false, new \DateTimeImmutable('-59 minutes'), null);
            $this->entityManager->persist($attempt);
        }
        $this->entityManager->persist(new LogEntry($alice, new ExerciseCompleted(
            $alice->getId()->toRfc4122(), ExerciseType::PuzzleRated, true, 20_000, 1, 'test', '1', new \DateTimeImmutable('-59 minutes'),
        )));
        $this->entityManager->persist(new Session($alice, 'Matinale', '', [['module' => Module::Free, 'minutes' => 10, 'notes' => '', 'settings' => []]], new \DateTimeImmutable('-2 hours'), new \DateTimeImmutable('+1 day')));
        $identity = new AuthIdentity($alice, AuthProvider::Lichess, 'magnus');
        self::getContainer()->get(OAuthTokenVault::class)->store($identity, self::LICHESS_TOKEN);
        $this->entityManager->persist($identity);
        $this->entityManager->flush();
        self::getContainer()->get(RepertoireManager::class)->create($alice, 'Italienne', Color::White);

        $zip = $this->export($alice);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        sort($names);
        self::assertSame(['LISEZMOI.txt', 'activite.json', 'entrainement.json', 'profil.json', 'puzzles.json', 'repertoires.json', 'repertoires/01-italienne.pgn', 'woodpecker.json'], $names);

        $profile = $this->json($zip, 'profil.json');
        self::assertSame('alice@example.com', $profile['email']);
        self::assertSame([['provider' => 'lichess', 'providerUserId' => 'magnus']], array_map(
            static fn (array $account): array => ['provider' => $account['provider'], 'providerUserId' => $account['providerUserId']],
            $this->listOf($profile['linkedAccounts']),
        ));
        $puzzles = $this->json($zip, 'puzzles.json');
        $attempts = $this->listOf($puzzles['attempts']);
        self::assertCount(1, $attempts, 'Bob’s attempt left out');
        self::assertSame(['Ab1Cd', 'solved', ['h1h2']], [$attempts[0]['puzzle'], $attempts[0]['status'], $attempts[0]['moves']]);
        $startedAt = $attempts[0]['started_at'];
        self::assertIsString($startedAt);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $startedAt, 'UTC, ISO 8601');
        self::assertCount(1, $this->listOf($this->json($zip, 'activite.json')['entries']));
        self::assertSame('Matinale', $this->listOf($this->json($zip, 'entrainement.json')['sessions'])[0]['title']);
        self::assertSame('Italienne', $this->listOf($this->json($zip, 'repertoires.json')['repertoires'])[0]['name']);

        $everything = '';
        foreach ($names as $name) {
            $everything .= (string) $zip->getFromName($name);
        }
        self::assertStringNotContainsString(self::LICHESS_TOKEN, $everything);
        self::assertStringNotContainsString('$2y$', $everything, 'no password hash');
        self::assertStringNotContainsString('bob@example.com', $everything);
        self::assertStringNotContainsStringIgnoringCase('token', $this->rawJson($zip, 'profil.json'));
    }

    public function testAFrozenAccountExportsAndThreeExportsADay(): void
    {
        $alice = $this->createUser('alice@example.com');
        $alice->scheduleDeletion(new \DateTimeImmutable('+30 days'));
        $this->entityManager->flush();

        $this->export($alice);
        $this->export($alice);
        $this->export($alice);
        $this->request($alice);
        self::assertSame(429, $this->client->getResponse()->getStatusCode());
    }

    private function createUser(string $email): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setPassword('$2y$04$abcdefghijklmnopqrstuuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZa');
        $user->markEmailVerified();
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function request(User $user): void
    {
        $this->client->request('GET', '/api/profile/export', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::getContainer()->get(JWTTokenManagerInterface::class)->create($user),
        ]);
    }

    /** Downloads the export and opens it. */
    private function export(User $user): \ZipArchive
    {
        $this->request($user);
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/zip', $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment; filename=chessmate-export-', (string) $response->headers->get('Content-Disposition'));

        // The test client captured the sent file (deleted after sending).
        $path = (string) tempnam(sys_get_temp_dir(), 'export-test-');
        $this->files[] = $path;
        file_put_contents($path, $this->client->getInternalResponse()->getContent());
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));

        return $zip;
    }

    private function rawJson(\ZipArchive $zip, string $name): string
    {
        $content = $zip->getFromName($name);
        self::assertIsString($content, $name);

        return $content;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(\ZipArchive $zip, string $name): array
    {
        $decoded = json_decode($this->rawJson($zip, $name), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listOf(mixed $value): array
    {
        self::assertIsArray($value);

        /** @var list<array<string, mixed>> $value */
        return $value;
    }
}
