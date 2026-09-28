<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Stamps every access token with the user's current token version — the claim that
 * {@see \App\Security\UserProvider::loadUserByIdentifierAndPayload()} checks on every request.
 */
#[AsEventListener(event: Events::JWT_CREATED)]
final class JwtTokenVersionListener
{
    public const CLAIM = 'ver';

    public function __invoke(JWTCreatedEvent $event): void
    {
        $user = $event->getUser();

        if (!$user instanceof User) {
            return;
        }

        $payload = $event->getData();
        $payload[self::CLAIM] = $user->getTokenVersion();
        $event->setData($payload);
    }
}
