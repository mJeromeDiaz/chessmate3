<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use App\Entity\User;
use App\Security\RateLimit\RateLimitGuard;
use App\Shop\Admin\AdminCatalog;
use App\Shop\Admin\InvalidPayload;
use App\Shop\Admin\OfferEditor;
use App\Shop\Admin\Payload;
use App\Shop\Admin\PlayerWallets;
use App\Shop\Admin\ShopStats;
use App\Shop\Admin\SlideEditor;
use App\Shop\Wallet\CrownAdjustment;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * The store's administration (docs/SHOP.md), admins only: the catalogue (offers, carousel), the
 * players' crowns (search, detail, give or take back with a reason), the figures. Writes within
 * the admin's budget (admin_write); a form refused answers 422 `{violations}`.
 */
#[Route('/api/admin/shop')]
#[IsGranted('ROLE_ADMIN')]
final class AdminShopController extends AbstractController
{
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function __construct(
        private readonly AdminCatalog $catalog,
        private readonly OfferEditor $offers,
        private readonly SlideEditor $slides,
        private readonly PlayerWallets $wallets,
        private readonly CrownAdjustment $crowns,
        private readonly ShopStats $stats,
        private readonly RateLimitGuard $rateLimitGuard,
        #[Autowire(service: 'limiter.admin_write')]
        private readonly RateLimiterFactory $adminWriteLimiter,
    ) {
    }

    #[Route('/catalog', name: 'admin_shop_catalog', methods: ['GET'])]
    public function catalog(): JsonResponse
    {
        return $this->json($this->catalog->view());
    }

    #[Route('/offers', name: 'admin_shop_offer_create', methods: ['POST'])]
    public function createOffer(Request $request, #[CurrentUser] User $admin): JsonResponse
    {
        return $this->write($admin, function () use ($request): JsonResponse {
            $this->offers->create($request->toArray());

            return $this->json($this->catalog->view(), Response::HTTP_CREATED);
        });
    }

    #[Route('/offers/{id}', name: 'admin_shop_offer_update', requirements: ['id' => self::UUID], methods: ['PUT'])]
    public function updateOffer(string $id, Request $request, #[CurrentUser] User $admin): JsonResponse
    {
        return $this->write($admin, fn (): JsonResponse => null === $this->offers->update(Uuid::fromString($id), $request->toArray())
            ? $this->json(['detail' => 'Offer not found.'], Response::HTTP_NOT_FOUND)
            : $this->json($this->catalog->view()));
    }

    #[Route('/slides', name: 'admin_shop_slide_create', methods: ['POST'])]
    public function createSlide(Request $request, #[CurrentUser] User $admin): JsonResponse
    {
        return $this->write($admin, function () use ($request): JsonResponse {
            $this->slides->create($request->toArray());

            return $this->json($this->catalog->view(), Response::HTTP_CREATED);
        });
    }

    #[Route('/slides/{id}', name: 'admin_shop_slide_update', requirements: ['id' => self::UUID], methods: ['PUT'])]
    public function updateSlide(string $id, Request $request, #[CurrentUser] User $admin): JsonResponse
    {
        return $this->write($admin, fn (): JsonResponse => null === $this->slides->update(Uuid::fromString($id), $request->toArray())
            ? $this->json(['detail' => 'Slide not found.'], Response::HTTP_NOT_FOUND)
            : $this->json($this->catalog->view()));
    }

    #[Route('/slides/{id}', name: 'admin_shop_slide_delete', requirements: ['id' => self::UUID], methods: ['DELETE'])]
    public function deleteSlide(string $id, #[CurrentUser] User $admin): JsonResponse
    {
        return $this->write($admin, fn (): JsonResponse => $this->slides->remove(Uuid::fromString($id))
            ? $this->json($this->catalog->view())
            : $this->json(['detail' => 'Slide not found.'], Response::HTTP_NOT_FOUND));
    }

    #[Route('/players', name: 'admin_shop_players', methods: ['GET'])]
    public function players(Request $request): JsonResponse
    {
        return $this->json(['players' => $this->wallets->search(trim($request->query->getString('search')))]);
    }

    #[Route('/players/{id}', name: 'admin_shop_player', requirements: ['id' => self::UUID], methods: ['GET'])]
    public function player(string $id): JsonResponse
    {
        $detail = $this->wallets->detail(Uuid::fromString($id));

        return null === $detail ? $this->json(['detail' => 'Account not found.'], Response::HTTP_NOT_FOUND) : $this->json($detail);
    }

    /**
     * POST {delta, reason}: crowns given (positive) or taken back (negative); 409 below zero.
     */
    #[Route('/players/{id}/crowns', name: 'admin_shop_player_crowns', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function adjust(string $id, Request $request, #[CurrentUser] User $admin): JsonResponse
    {
        return $this->write($admin, function () use ($id, $request, $admin): JsonResponse {
            $userId = Uuid::fromString($id);
            if (null === $this->wallets->detail($userId)) {
                return $this->json(['detail' => 'Account not found.'], Response::HTTP_NOT_FOUND);
            }
            $payload = new Payload($request->toArray());
            $delta = $payload->int('delta', -1_000_000, 1_000_000);
            if (0 === $delta) {
                $payload->fail('delta', 'Un nombre différent de 0 est attendu.');
            }
            $reason = $payload->string('reason', 255);
            $payload->done();
            \assert(null !== $delta);
            try {
                $this->crowns->adjust($userId, $delta, $reason, $admin->getId());
            } catch (\DomainException $exception) {
                return $this->json(['detail' => $exception->getMessage()], Response::HTTP_CONFLICT);
            }

            return $this->json($this->wallets->detail($userId));
        });
    }

    #[Route('/stats', name: 'admin_shop_stats', methods: ['GET'])]
    public function stats(Request $request): JsonResponse
    {
        $days = $request->query->getInt('days', 30);

        return $this->json($this->stats->of(\in_array($days, ShopStats::PERIODS, true) ? $days : 30));
    }

    /**
     * A write within the admin's budget; a refused form answers 422.
     *
     * @param \Closure(): JsonResponse $write
     */
    private function write(User $admin, \Closure $write): JsonResponse
    {
        $this->rateLimitGuard->consume($this->adminWriteLimiter, $admin->getId()->toRfc4122());
        try {
            return $write();
        } catch (InvalidPayload $invalid) {
            return $this->json(['violations' => $invalid->violations], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Symfony\Component\HttpFoundation\Exception\JsonException) {
            return $this->json(['detail' => 'Invalid JSON.'], Response::HTTP_BAD_REQUEST);
        }
    }
}
