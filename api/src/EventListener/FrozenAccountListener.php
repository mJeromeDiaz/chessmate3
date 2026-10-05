<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A frozen account (deletion scheduled, docs/AUTH.md) only reaches the authentication endpoints
 * (the cancellation is one of them, under /api/auth/account-deletion), its profile (and the
 * timezone and theme the SPA reports on sign-in) and the export of its data; anything else answers 403 `account_frozen` with the purge date. Priority 7:
 * right after the firewall (8), which has authenticated the request.
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: 7)]
final class FrozenAccountListener
{
    public const ALLOWED_ROUTES = [
        'app_profile_show',
        'app_profile_timezone',
        'app_profile_theme',
        'app_profile_export',
    ];

    public function __construct(private readonly Security $security)
    {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api/') || str_starts_with($request->getPathInfo(), '/api/auth/')) {
            return;
        }
        if (\in_array($request->attributes->get('_route'), self::ALLOWED_ROUTES, true)) {
            return;
        }
        $user = $this->security->getUser();
        if (!$user instanceof User || !$user->isFrozen()) {
            return;
        }

        $event->setResponse(new JsonResponse([
            'error' => 'account_frozen',
            'message' => 'This account is scheduled for deletion: cancel it to use the account again.',
            'deletionScheduledAt' => $user->getDeletionScheduledAt()?->format(\DATE_ATOM),
        ], Response::HTTP_FORBIDDEN));
    }
}
