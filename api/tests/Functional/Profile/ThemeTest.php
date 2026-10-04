<?php

declare(strict_types=1);

namespace App\Tests\Functional\Profile;

use App\Entity\User;
use App\Enum\Theme;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ThemeTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        self::getContainer()->get('cache.rate_limiter')->clear();
    }

    public function testTheThemeIsUnsetUntilChosenThenKept(): void
    {
        $server = $this->signedIn('alice@example.com');

        $this->client->request('GET', '/api/profile', server: $server);
        self::assertNull($this->decode()['theme']);

        foreach (['dark', 'light', 'auto'] as $theme) {
            $this->client->request('PUT', '/api/profile/theme', server: $server, content: json_encode(['theme' => $theme], \JSON_THROW_ON_ERROR));
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            self::assertSame($theme, $this->decode()['theme']);
        }

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertSame(Theme::Auto, self::getContainer()->get(UserRepository::class)->findOneByEmail('alice@example.com')?->getTheme());
    }

    public function testAnUnknownThemeIsRefused(): void
    {
        $server = $this->signedIn('bob@example.com');

        foreach (['{"theme":"sepia"}', '{"theme":""}', '{}'] as $content) {
            $this->client->request('PUT', '/api/profile/theme', server: $server, content: $content);
            self::assertSame(422, $this->client->getResponse()->getStatusCode(), $content);
        }
    }

    public function testTheThemeNeedsASignedInUser(): void
    {
        $this->client->request('PUT', '/api/profile/theme', server: ['CONTENT_TYPE' => 'application/json'], content: '{"theme":"dark"}');

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
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
