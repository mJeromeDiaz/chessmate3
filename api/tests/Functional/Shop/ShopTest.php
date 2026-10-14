<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shop;

use App\Entity\Shop\Offer;
use App\Entity\User;
use App\Repository\Shop\OfferRepository;
use App\Shop\Wallet\CrownAdjustment;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The store (docs/SHOP.md): the default catalogue, purchases paid at once in crowns, never below
 * zero, the items in the inventory.
 *
 * @phpstan-type Store array{balance: int, offers: list<array{id: string, slug: string, section: string, subtitle: string|null, module: string|null, items: list<mixed>, price: int, wasPrice: int|null, comingSoon: bool}>, slides: list<array{offerId: string, tag: string}>, inventory: array<string, int>, freezes: list<array{item: string, days: int, daysLeft: int}>}
 */
final class ShopTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::getContainer()->get('cache.rate_limiter')->clear();
    }

    public function testTheStoreListsTheDefaultCatalogueInOrder(): void
    {
        $store = $this->store($this->createUser());

        self::assertSame(0, $store['balance']);
        self::assertSame([], $store['inventory']);
        $sections = array_values(array_unique(array_column($store['offers'], 'section')));
        self::assertSame(['freeze', 'puzzle_streak', 'aids', 'packs', 'cosmetics'], $sections);
        $bySlug = array_column($store['offers'], null, 'slug');
        self::assertSame(['freeze_1', 'freeze_2', 'freeze_7', 'freeze_14'], \array_slice(array_column($store['offers'], 'slug'), 0, 4));
        self::assertSame([350, 420, 'Les vacances'], [$bySlug['freeze_7']['price'], $bySlug['freeze_7']['wasPrice'], $bySlug['freeze_7']['subtitle']]);
        self::assertSame(['blindfold', true, []], [$bySlug['blindfold_pardon']['module'], $bySlug['cosmetic_sounds']['comingSoon'], $bySlug['cosmetic_sounds']['items']]);
        self::assertNull($bySlug['pack_holidays']['module'], 'a pack spans modules');
        self::assertSame(['OFFRE DU MOMENT', 'POUR LES ACHARNÉS', 'ZÉRO STRESS', 'YEUX FERMÉS'], array_column($store['slides'], 'tag'));
        self::assertSame($bySlug['pack_holidays']['id'], $store['slides'][0]['offerId']);
    }

    public function testAPurchaseIsPaidAtOnceAndFillsTheInventory(): void
    {
        $alice = $this->createUser();
        $holidays = $this->offer('pack_holidays');

        $refused = $this->buy($alice, $holidays, 1);
        self::assertSame(409, $refused->getStatusCode());
        self::assertStringContainsString('insufficient', (string) $refused->getContent());
        self::assertSame(0, $this->rowCount('shop_ledger_entry', $alice));

        self::getContainer()->get(CrownAdjustment::class)->adjust($alice->getId(), 500, 'Test', null);
        $response = $this->buy($alice, $holidays, 1);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        /** @var array{balance: int, inventory: array<string, int>, freezes: list<array{item: string, days: int, daysLeft: int}>} $store */
        $store = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(50, $store['balance']);
        self::assertSame(['puzzle_streak_pass' => 3, 'streak_freeze_7' => 1], $this->sorted($store['inventory']));
        self::assertSame([['streak_freeze_7', 7, 7]], array_map(static fn (array $f): array => [$f['item'], $f['days'], $f['daysLeft']], $store['freezes']));
        self::assertSame(
            [['grant', 500, 500, null, null], ['purchase', -450, 50, 'Pack Vacances', 1]],
            array_map(static fn (array $row): array => [$row['kind'], self::int($row['delta']), self::int($row['balance_after']), $row['offer_name'], self::int($row['quantity'])], $this->connection()->fetchAllAssociative(
                'SELECT kind, delta, balance_after, offer_name, quantity FROM shop_ledger_entry WHERE user_id = ? ORDER BY created_at, id',
                [$alice->getId()->toBinary()],
            )),
        );

        // Twice as much as what is left: refused, nothing moves.
        self::assertSame(409, $this->buy($alice, $this->offer('freeze_1'), 2)->getStatusCode());
        self::assertSame(50, $this->store($alice)['balance']);

        // A quantity buys several, each freeze on its own.
        self::getContainer()->get(CrownAdjustment::class)->adjust($alice->getId(), 70, null, null);
        self::assertSame(200, $this->buy($alice, $this->offer('freeze_1'), 2)->getStatusCode());
        $store = $this->store($alice);
        self::assertSame([0, 3], [$store['balance'], \count($store['freezes'])]);
        self::assertSame([1, 1, 7], array_column($store['freezes'], 'daysLeft'), 'the shortest first');
    }

    public function testOnlyOffersOnSaleAreSold(): void
    {
        $alice = $this->createUser();
        self::getContainer()->get(CrownAdjustment::class)->adjust($alice->getId(), 1000, null, null);

        self::assertSame(409, $this->buy($alice, $this->offer('cosmetic_sounds'), 1)->getStatusCode(), 'coming soon');
        $offer = $this->entityManager->getRepository(Offer::class)->findOneBy(['slug' => 'puzzle_pardon']);
        self::assertInstanceOf(Offer::class, $offer);
        $offer->update($offer->getSection(), $offer->getName(), null, $offer->getDescription(), $offer->getContents(), 40, null, 1, null, new \DateTimeImmutable('-1 hour'), false, false, new \DateTimeImmutable());
        $this->entityManager->flush();
        self::assertSame(409, $this->buy($alice, $offer->getId()->toRfc4122(), 1)->getStatusCode(), 'its sale is over');
        self::assertNotContains('puzzle_pardon', array_column($this->store($alice)['offers'], 'slug'));

        self::assertSame(404, $this->buy($alice, '0192f6c4-0000-7000-8000-000000000000', 1)->getStatusCode());
        self::assertSame(422, $this->buy($alice, $this->offer('freeze_1'), 10)->getStatusCode());
        self::assertSame(422, $this->buy($alice, $this->offer('freeze_1'), 0)->getStatusCode());
        self::assertSame(1000, $this->store($alice)['balance']);
    }

    public function testNeverBelowZero(): void
    {
        $alice = $this->createUser();
        $crowns = self::getContainer()->get(CrownAdjustment::class);
        $crowns->adjust($alice->getId(), 30, null, null);

        $this->expectException(\DomainException::class);
        $crowns->adjust($alice->getId(), -31, 'Trop', null);
    }

    private function createUser(): User
    {
        $user = new User();
        $user->setEmail('alice@example.com');
        $user->markEmailVerified();
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function offer(string $slug): string
    {
        $offer = self::getContainer()->get(OfferRepository::class)->findOneBy(['slug' => $slug]);
        self::assertInstanceOf(Offer::class, $offer, $slug);

        return $offer->getId()->toRfc4122();
    }

    private function buy(User $user, string $offerId, int $quantity): Response
    {
        $this->client->request('POST', '/api/shop/purchases', server: $this->headers($user), content: json_encode(['offerId' => $offerId, 'quantity' => $quantity], \JSON_THROW_ON_ERROR));

        return $this->client->getResponse();
    }

    /**
     * @return Store
     */
    private function store(User $user): array
    {
        $this->client->request('GET', '/api/shop', server: $this->headers($user));
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var Store */
        return json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, string>
     */
    private function headers(User $user): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::getContainer()->get(JWTTokenManagerInterface::class)->create($user),
            'HTTP_ACCEPT' => 'application/ld+json',
            'CONTENT_TYPE' => 'application/ld+json',
        ];
    }

    /**
     * @param array<string, int> $items
     *
     * @return array<string, int>
     */
    private function sorted(array $items): array
    {
        ksort($items);

        return $items;
    }

    private function rowCount(string $table, User $user): int
    {
        $count = $this->connection()->fetchOne(\sprintf('SELECT COUNT(*) FROM %s WHERE user_id = ?', $table), [$user->getId()->toBinary()]);

        return is_numeric($count) ? (int) $count : -1;
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
