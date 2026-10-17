<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shop;

use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The store's administration (docs/SHOP.md): offers and carousel, players' crowns, figures;
 * admins only.
 */
final class AdminShopTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $admin;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::getContainer()->get('cache.rate_limiter')->clear();
        $this->admin = $this->createUser('admin@example.com', true);
    }

    public function testAdminsOnly(): void
    {
        $alice = $this->createUser('alice@example.com');
        foreach ([['GET', '/api/admin/shop/catalog'], ['GET', '/api/admin/shop/players'], ['GET', '/api/admin/shop/stats'], ['POST', '/api/admin/shop/offers'], ['GET', '/api/admin/shop/reward-rule'], ['PUT', '/api/admin/shop/reward-rule']] as [$method, $uri]) {
            self::assertSame(403, $this->call($alice, $method, $uri, [])->getStatusCode(), $uri);
        }
    }

    public function testARefusalIsWrittenInTheLanguageOfTheRequest(): void
    {
        $offer = ['slug' => 'pack_x', 'section' => 'packs', 'name' => 'X', 'description' => 'x', 'contents' => [['item' => 'puzzle_pardon', 'quantity' => 1]], 'price' => 100, 'wasPrice' => 90];

        $titles = fn (Response $response): array => array_column($this->listOf($this->json($response)['violations']), 'title', 'propertyPath');
        self::assertSame(['wasPrice' => 'The struck-through price must be above the price.'], $titles($this->call($this->admin, 'POST', '/api/admin/shop/offers', $offer)));
        self::assertSame(['wasPrice' => 'Le prix barré doit dépasser le prix.'], $titles($this->call($this->admin, 'POST', '/api/admin/shop/offers', $offer, 'fr')));
        $long = $this->call($this->admin, 'POST', '/api/admin/shop/offers', ['name' => str_repeat('x', 200)] + $offer, 'fr');
        self::assertStringEndsWith('caractères au plus.', (string) ($titles($long)['name'] ?? ''));
    }

    public function testAnOfferIsCreatedCheckedAndSuspended(): void
    {
        $catalog = $this->json($this->call($this->admin, 'GET', '/api/admin/shop/catalog'));
        self::assertCount(16, $this->listOf($catalog['offers']));
        self::assertCount(4, $this->listOf($catalog['slides']));

        $refused = $this->call($this->admin, 'POST', '/api/admin/shop/offers', [
            'slug' => 'Pack Été', 'section' => 'packs', 'name' => 'Pack Été', 'description' => 'Soleil',
            'contents' => [['item' => 'sunscreen', 'quantity' => 1], ['item' => 'puzzle_pardon', 'quantity' => 0]],
            'price' => 100, 'wasPrice' => 90,
        ]);
        self::assertSame(422, $refused->getStatusCode());
        self::assertEqualsCanonicalizing(
            ['slug', 'wasPrice', 'contents[0].item', 'contents[1].quantity', 'contents'],
            array_column($this->listOf($this->json($refused)['violations']), 'propertyPath'),
        );
        self::assertSame(422, $this->call($this->admin, 'POST', '/api/admin/shop/offers', [
            'slug' => 'freeze_1', 'section' => 'freeze', 'name' => 'Doublon', 'description' => 'x', 'contents' => [['item' => 'streak_freeze_1', 'quantity' => 1]], 'price' => 1,
        ])->getStatusCode(), 'a slug is unique');

        $summer = [
            'slug' => 'pack_summer', 'section' => 'packs', 'name' => 'Pack Été', 'description' => 'Deux gels et un pardon.',
            'contents' => [['item' => 'streak_freeze_2', 'quantity' => 2], ['item' => 'puzzle_pardon', 'quantity' => 1]],
            'price' => 200, 'wasPrice' => 260, 'position' => 9,
        ];
        $created = $this->call($this->admin, 'POST', '/api/admin/shop/offers', $summer);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getContent());
        $offer = $this->offerBySlug($this->json($created), 'pack_summer');
        $offerId = $offer['id'];
        self::assertIsString($offerId);
        self::assertSame([true, 0, null], [$offer['onSale'], $offer['sales'], $offer['module']]);
        self::assertContains('pack_summer', $this->storeSlugs());

        // Suspended: off the store, still in the admin's catalogue.
        $updated = $this->call($this->admin, 'PUT', '/api/admin/shop/offers/'.$offerId, ['suspended' => true] + $summer);
        self::assertSame(200, $updated->getStatusCode(), (string) $updated->getContent());
        self::assertSame([true, false], [$this->offerBySlug($this->json($updated), 'pack_summer')['suspended'], $this->offerBySlug($this->json($updated), 'pack_summer')['listed']]);
        self::assertNotContains('pack_summer', $this->storeSlugs());

        // A sale window to come: not listed yet.
        $this->call($this->admin, 'PUT', '/api/admin/shop/offers/'.$offerId, ['startsAt' => '2099-01-01T00:00:00+00:00'] + $summer);
        self::assertNotContains('pack_summer', $this->storeSlugs());
        self::assertSame(404, $this->call($this->admin, 'PUT', '/api/admin/shop/offers/0192f6c4-0000-7000-8000-000000000000', $summer)->getStatusCode());
    }

    public function testTheCarousel(): void
    {
        $catalog = $this->json($this->call($this->admin, 'GET', '/api/admin/shop/catalog'));
        $pardon = $this->offerBySlug($catalog, 'puzzle_pardon');

        $created = $this->call($this->admin, 'POST', '/api/admin/shop/slides', ['offerId' => $pardon['id'], 'tag' => 'PROMO', 'image' => 'lizy-joy', 'position' => 0]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getContent());
        $slides = $this->listOf($this->json($created)['slides']);
        self::assertSame(['PROMO', 'lizy-joy', true], [$slides[0]['tag'], $slides[0]['image'], $slides[0]['shown']]);
        self::assertSame('PROMO', $this->store()['slides'][0]['tag']);

        $id = $slides[0]['id'];
        self::assertIsString($id);
        $this->call($this->admin, 'PUT', '/api/admin/shop/slides/'.$id, ['offerId' => $pardon['id'], 'tag' => 'PROMO', 'image' => 'lizy-joy', 'suspended' => true]);
        self::assertNotContains('PROMO', array_column($this->store()['slides'], 'tag'));
        self::assertSame(422, $this->call($this->admin, 'POST', '/api/admin/shop/slides', ['offerId' => 'nope', 'tag' => '', 'image' => 'Albert Cool'])->getStatusCode());

        self::assertSame(200, $this->call($this->admin, 'DELETE', '/api/admin/shop/slides/'.$id)->getStatusCode());
        self::assertCount(4, $this->listOf($this->json($this->call($this->admin, 'GET', '/api/admin/shop/catalog'))['slides']));
        self::assertSame(404, $this->call($this->admin, 'DELETE', '/api/admin/shop/slides/'.$id)->getStatusCode());
    }

    public function testCrownsAreGivenAndTakenBackWithAReasonThenCounted(): void
    {
        $alice = $this->createUser('alice@example.com');
        $aliceId = $alice->getId()->toRfc4122();

        self::assertSame(422, $this->call($this->admin, 'POST', "/api/admin/shop/players/$aliceId/crowns", ['delta' => 100])->getStatusCode(), 'a reason is required');
        $given = $this->call($this->admin, 'POST', "/api/admin/shop/players/$aliceId/crowns", ['delta' => 500, 'reason' => 'Bêta-testeuse']);
        self::assertSame(200, $given->getStatusCode(), (string) $given->getContent());
        $player = $this->json($given)['player'];
        self::assertIsArray($player);
        self::assertSame(500, $player['balance'] ?? null);

        $taken = $this->call($this->admin, 'POST', "/api/admin/shop/players/$aliceId/crowns", ['delta' => -600, 'reason' => 'Trop']);
        self::assertSame(409, $taken->getStatusCode());
        $this->call($this->admin, 'POST', "/api/admin/shop/players/$aliceId/crowns", ['delta' => -100, 'reason' => 'Erreur de saisie']);

        // She buys a freeze.
        $catalog = $this->json($this->call($this->admin, 'GET', '/api/admin/shop/catalog'));
        $this->call($alice, 'POST', '/api/shop/purchases', ['offerId' => $this->offerBySlug($catalog, 'freeze_7')['id'], 'quantity' => 1]);

        $players = $this->listOf($this->json($this->call($this->admin, 'GET', '/api/admin/shop/players?search=alice'))['players']);
        self::assertSame([['alice@example.com', 50, 350]], array_map(static fn (array $p): array => [$p['email'], $p['balance'], $p['spent']], $players));

        $detail = $this->json($this->call($this->admin, 'GET', "/api/admin/shop/players/$aliceId"));
        self::assertSame(['streak_freeze_7' => 1], $detail['inventory']);
        $ledger = $this->listOf($detail['ledger']);
        self::assertSame(
            [['purchase', -350, null], ['revoke', -100, 'admin@example.com'], ['grant', 500, 'admin@example.com']],
            array_map(static fn (array $l): array => [$l['kind'], $l['delta'], $l['admin']], $ledger),
        );
        self::assertSame('Bêta-testeuse', $ledger[2]['reason']);
        self::assertSame(404, $this->call($this->admin, 'GET', '/api/admin/shop/players/0192f6c4-0000-7000-8000-000000000000')->getStatusCode());

        $stats = $this->json($this->call($this->admin, 'GET', '/api/admin/shop/stats?days=7'));
        self::assertSame(['granted' => 500, 'revoked' => 100, 'spent' => 350, 'purchases' => 1, 'buyers' => 1], array_intersect_key(
            \is_array($stats['totals']) ? $stats['totals'] : [],
            array_flip(['granted', 'revoked', 'spent', 'purchases', 'buyers']),
        ));
        self::assertSame([['Gel 1 semaine', 1, 1, 350]], array_map(static fn (array $o): array => [$o['offerName'], $o['purchases'], $o['quantity'], $o['crowns']], $this->listOf($stats['offers'])));
        self::assertCount(7, $this->listOf($stats['daily']));
        self::assertSame(['streak_freeze_7' => 1], $stats['held']);
        self::assertSame(7, $stats['freezeDays']);
    }

    public function testTheRewardRuleIsSetByAnAdmin(): void
    {
        self::assertSame(
            ['crownsPerHour' => 120, 'perfectPercent' => 150, 'nearPercent' => 100, 'missedPercent' => 50, 'dailyMinutes' => 60, 'updatedAt' => null, 'updatedBy' => null],
            $this->json($this->call($this->admin, 'GET', '/api/admin/shop/reward-rule')),
        );

        $refused = $this->call($this->admin, 'PUT', '/api/admin/shop/reward-rule', ['crownsPerHour' => -1, 'perfectPercent' => 150, 'nearPercent' => 100, 'missedPercent' => 50]);
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(['crownsPerHour', 'dailyMinutes'], array_column($this->listOf($this->json($refused)['violations']), 'propertyPath'));

        $saved = $this->call($this->admin, 'PUT', '/api/admin/shop/reward-rule', ['crownsPerHour' => 90, 'perfectPercent' => 200, 'nearPercent' => 100, 'missedPercent' => 25, 'dailyMinutes' => 45]);
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getContent());
        $rule = $this->json($saved);
        self::assertSame([90, 200, 25, 45, 'admin@example.com'], [$rule['crownsPerHour'], $rule['perfectPercent'], $rule['missedPercent'], $rule['dailyMinutes'], $rule['updatedBy']]);
        self::assertIsString($rule['updatedAt']);

        $earning = $this->json($this->call($this->createUser('alice@example.com'), 'GET', '/api/shop'))['earning'] ?? null;
        self::assertIsArray($earning);
        self::assertSame([90, 45, 45], [$earning['crownsPerHour'] ?? null, $earning['dailyMinutes'] ?? null, $earning['minutesLeftToday'] ?? null]);
    }

    private function createUser(string $email, bool $admin = false): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->markEmailVerified();
        if ($admin) {
            $user->setRoles(['ROLE_ADMIN']);
        }
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * @param array<string, mixed>|null $body
     * @param string                    $language the Accept-Language the SPA sends
     */
    private function call(User $user, string $method, string $uri, ?array $body = null, string $language = 'en'): Response
    {
        $this->client->request($method, $uri, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::getContainer()->get(JWTTokenManagerInterface::class)->create($user),
            'HTTP_ACCEPT' => 'application/ld+json',
            'CONTENT_TYPE' => 'application/ld+json',
            'HTTP_ACCEPT_LANGUAGE' => $language,
        ], content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));

        return $this->client->getResponse();
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listOf(mixed $value): array
    {
        self::assertIsArray($value);

        /** @var list<array<string, mixed>> */
        return array_values($value);
    }

    /**
     * @param array<string, mixed> $catalog
     *
     * @return array<string, mixed>
     */
    private function offerBySlug(array $catalog, string $slug): array
    {
        foreach ($this->listOf($catalog['offers']) as $offer) {
            if ($slug === $offer['slug']) {
                return $offer;
            }
        }
        self::fail("No offer $slug.");
    }

    /**
     * @return array{slides: list<array<string, mixed>>, offers: list<array<string, mixed>>}
     */
    private function store(): array
    {
        $response = $this->call($this->admin, 'GET', '/api/shop');
        self::assertSame(200, $response->getStatusCode());
        $store = $this->json($response);

        return ['slides' => $this->listOf($store['slides']), 'offers' => $this->listOf($store['offers'])];
    }

    /**
     * @return list<mixed>
     */
    private function storeSlugs(): array
    {
        return array_column($this->store()['offers'], 'slug');
    }
}
