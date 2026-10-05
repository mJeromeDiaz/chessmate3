<?php

declare(strict_types=1);

namespace App\Tests\Functional\Profile;

use App\Entity\User;
use App\Enum\BoardTheme;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Board colours, move sounds and public profile flag (PUT /api/profile/preferences). */
final class PreferencesTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        self::getContainer()->get('cache.rate_limiter')->clear();
    }

    public function testDefaultsThenSavedPreferences(): void
    {
        $server = $this->signedIn('alice@example.com');

        $this->client->request('GET', '/api/profile', server: $server);
        $profile = $this->decode();
        self::assertSame('wood', $profile['boardTheme']);
        self::assertTrue($profile['moveSound']);
        self::assertFalse($profile['publicProfile']);

        $this->put($server, ['boardTheme' => 'slate', 'moveSound' => false, 'publicProfile' => true]);
        self::assertResponseIsSuccessful();
        $profile = $this->decode();
        self::assertSame('slate', $profile['boardTheme']);
        self::assertFalse($profile['moveSound']);
        self::assertTrue($profile['publicProfile']);

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $user = self::getContainer()->get(UserRepository::class)->findOneByEmail('alice@example.com');
        self::assertNotNull($user);
        self::assertSame(BoardTheme::Slate, $user->getBoardTheme());
        self::assertFalse($user->hasMoveSound());
        self::assertTrue($user->isPublicProfile());
    }

    public function testInvalidOrMissingValuesAreRefused(): void
    {
        $server = $this->signedIn('bob@example.com');

        foreach ([
            ['boardTheme' => 'marble', 'moveSound' => true, 'publicProfile' => false],
            ['boardTheme' => 'wood', 'publicProfile' => false],
            ['boardTheme' => 'wood', 'moveSound' => true],
            ['moveSound' => true, 'publicProfile' => false],
            ['boardTheme' => 'wood', 'moveSound' => 'yes', 'publicProfile' => false],
        ] as $payload) {
            $this->put($server, $payload);
            self::assertResponseStatusCodeSame(422, (string) json_encode($payload));
        }
    }

    public function testThePreferencesNeedASignedInUser(): void
    {
        $this->client->request('PUT', '/api/profile/preferences', server: ['CONTENT_TYPE' => 'application/json'], content: '{"boardTheme":"wood","moveSound":true,"publicProfile":false}');

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @param array<string, string|bool> $server
     * @param array<string, string|bool> $payload
     */
    private function put(array $server, array $payload): void
    {
        $this->client->request('PUT', '/api/profile/preferences', server: $server, content: json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, string>
     */
    private function signedIn(string $email): array
    {
        $user = new User();
        $user->setEmail($email);
        $user->markEmailVerified();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }
}
