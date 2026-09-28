<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The authenticated user, for services that can't use #[CurrentUser] (API Platform state
 * providers and processors). The firewall already requires authentication on /api.
 */
final class AuthenticatedUser
{
    public function __construct(private readonly Security $security)
    {
    }

    public function get(): User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException();
        }

        return $user;
    }
}
