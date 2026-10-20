<?php

declare(strict_types=1);

namespace App\State\MindMap;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\MindMap\Chest;
use App\MindMap\Progress\ChestOpener;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Uid\Uuid;

/**
 * POST /mind-map/chests/{id}/open: a chest of a path seen (idempotent); 404 when it is not the
 * player's. Within the shop's write budget.
 *
 * @implements ProcessorInterface<mixed, Chest>
 */
final class OpenChestProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ChestOpener $opener,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $shopWriteLimiter,
        private readonly ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Chest
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->shopWriteLimiter, $user->getUserIdentifier());
        $id = $uriVariables['id'] ?? null;
        if (!\is_string($id) || !Uuid::isValid($id)) {
            throw new NotFoundHttpException();
        }
        $opened = $this->opener->open($user, Uuid::fromString($id), $this->clock->now());
        if (null === $opened) {
            throw new NotFoundHttpException('Chest not found.');
        }
        $view = new Chest();
        $view->id = $opened['chest']->getId()->toRfc4122();
        $view->capacity = $opened['chest']->getCapacity()->value;
        $view->step = $opened['chest']->getStep();
        $view->crystals = $opened['chest']->getCrystals();
        $view->opened = true;
        $view->balance = $opened['balance'];

        return $view;
    }
}
