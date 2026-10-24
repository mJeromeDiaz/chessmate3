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
use App\Shop\Gift\TicketGrants;
use App\Shop\Reward\RewardRules;
use App\Shop\Reward\Rule;
use App\Shop\Wallet\CrownAdjustment;
use App\Shop\Wheel\Odds;
use App\Shop\Wheel\WheelRules;
use Psr\Clock\ClockInterface;
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
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The store's administration (docs/SHOP.md), admins only: the catalogue (offers, carousel), the
 * players' crowns (search, detail, give or take back with a reason), gift wheel tickets given to a
 * player or to every active account (§ 4 quater), what sessions earn (§ 4 bis),
 * the gift wheel's odds (§ 4 ter), the figures. Writes within
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
        private readonly TicketGrants $tickets,
        private readonly ShopStats $stats,
        private readonly RewardRules $rewardRules,
        private readonly WheelRules $wheelRules,
        private readonly ClockInterface $clock,
        private readonly RateLimitGuard $rateLimitGuard,
        #[Autowire(service: 'limiter.admin_write')]
        private readonly RateLimiterFactory $adminWriteLimiter,
        private readonly TranslatorInterface $translator,
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
                $payload->fail('delta', 'shop.nonzero');
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

    /**
     * POST {quantity, message}: gift wheel tickets given to a player (never taken back), the
     * message shown to the player at the next visit.
     */
    #[Route('/players/{id}/wheel-tickets', name: 'admin_shop_player_wheel_tickets', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function giveTickets(string $id, Request $request, #[CurrentUser] User $admin): JsonResponse
    {
        return $this->write($admin, function () use ($id, $request, $admin): JsonResponse {
            $userId = Uuid::fromString($id);
            if (null === $this->wallets->detail($userId)) {
                return $this->json(['detail' => 'Account not found.'], Response::HTTP_NOT_FOUND);
            }
            [$quantity, $message] = $this->ticketGift(new Payload($request->toArray()));
            $this->tickets->toPlayer($userId, $quantity, $message, $admin->getId());

            return $this->json($this->wallets->detail($userId));
        });
    }

    /** The accounts a gift to everyone serves (neither suspended nor being deleted). */
    #[Route('/wheel-tickets/recipients', name: 'admin_shop_wheel_ticket_recipients', methods: ['GET'])]
    public function ticketRecipients(): JsonResponse
    {
        return $this->json(['count' => $this->tickets->recipients()]);
    }

    /**
     * POST {quantity, message}: gift wheel tickets given to every active account, with its line
     * in the journal (201: that line).
     */
    #[Route('/wheel-tickets', name: 'admin_shop_wheel_tickets', methods: ['POST'])]
    public function giveTicketsToEveryone(Request $request, #[CurrentUser] User $admin): JsonResponse
    {
        return $this->write($admin, function () use ($request, $admin): JsonResponse {
            [$quantity, $message] = $this->ticketGift(new Payload($request->toArray()));

            return $this->json($this->tickets->toEveryone($quantity, $message, $admin->getId()), Response::HTTP_CREATED);
        });
    }

    /** The journal of the gifts to everyone, the latest first. */
    #[Route('/grant-batches', name: 'admin_shop_grant_batches', methods: ['GET'])]
    public function grantBatches(): JsonResponse
    {
        return $this->json(['batches' => $this->tickets->batches()]);
    }

    #[Route('/reward-rule', name: 'admin_shop_reward_rule', methods: ['GET'])]
    public function rewardRule(): JsonResponse
    {
        return $this->json($this->rewardRules->view());
    }

    /**
     * PUT {crownsPerHour, perfectPercent, nearPercent, missedPercent, dailyMinutes}: the rule of
     * the next sessions (rewards already credited stay).
     */
    #[Route('/reward-rule', name: 'admin_shop_reward_rule_update', methods: ['PUT'])]
    public function updateRewardRule(Request $request, #[CurrentUser] User $admin): JsonResponse
    {
        return $this->write($admin, function () use ($request, $admin): JsonResponse {
            $payload = new Payload($request->toArray());
            $rule = new Rule(
                (int) $payload->int('crownsPerHour', 0, 10_000),
                (int) $payload->int('perfectPercent', 0, 1_000),
                (int) $payload->int('nearPercent', 0, 1_000),
                (int) $payload->int('missedPercent', 0, 1_000),
                (int) $payload->int('dailyMinutes', 0, 1_440),
            );
            $payload->done();
            $this->rewardRules->save($rule, $admin->getId(), $this->clock->now());

            return $this->json($this->rewardRules->view());
        });
    }

    #[Route('/wheel-rule', name: 'admin_shop_wheel_rule', methods: ['GET'])]
    public function wheelRule(): JsonResponse
    {
        return $this->json($this->wheelRules->view());
    }

    /**
     * PUT {c5, c30, c300, gift, lose, freezePercent}: the gift wheel's odds for the next spins
     * (weights, at least one above 0; a gift's chance to be a freeze, in percent).
     */
    #[Route('/wheel-rule', name: 'admin_shop_wheel_rule_update', methods: ['PUT'])]
    public function updateWheelRule(Request $request, #[CurrentUser] User $admin): JsonResponse
    {
        return $this->write($admin, function () use ($request, $admin): JsonResponse {
            $payload = new Payload($request->toArray());
            $odds = new Odds(
                (int) $payload->int('c5', 0, 10_000),
                (int) $payload->int('c30', 0, 10_000),
                (int) $payload->int('c300', 0, 10_000),
                (int) $payload->int('gift', 0, 10_000),
                (int) $payload->int('lose', 0, 10_000),
                (int) $payload->int('freezePercent', 0, 100),
            );
            if (!$odds->isValid()) {
                $payload->fail('c5', 'shop.wheel_no_prize');
            }
            $payload->done();
            $this->wheelRules->save($odds, $admin->getId(), $this->clock->now());

            return $this->json($this->wheelRules->view());
        });
    }

    #[Route('/stats', name: 'admin_shop_stats', methods: ['GET'])]
    public function stats(Request $request): JsonResponse
    {
        $days = $request->query->getInt('days', 30);

        return $this->json($this->stats->of(\in_array($days, ShopStats::PERIODS, true) ? $days : 30));
    }

    /**
     * A gift of tickets: how many, the message shown to the players.
     *
     * @return array{int, string}
     *
     * @throws InvalidPayload
     */
    private function ticketGift(Payload $payload): array
    {
        $quantity = $payload->int('quantity', 1, TicketGrants::MAX_QUANTITY);
        $message = $payload->string('message', TicketGrants::MESSAGE_LENGTH);
        $payload->done();
        \assert(null !== $quantity && null !== $message);

        return [$quantity, $message];
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
            // In the language of the admin's request (docs/I18N.md, § 5).
            return $this->json(['violations' => array_map(fn (array $violation): array => [
                'propertyPath' => $violation['propertyPath'],
                'title' => $this->translator->trans($violation['message'], $violation['parameters'], 'admin'),
            ], $invalid->violations)], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Symfony\Component\HttpFoundation\Exception\JsonException) {
            return $this->json(['detail' => 'Invalid JSON.'], Response::HTTP_BAD_REQUEST);
        }
    }
}
