<?php

declare(strict_types=1);

namespace App\State\Training;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Training\Session;
use App\Security\AuthenticatedUser;
use App\Training\Exception\SessionNotFoundException;
use App\Training\Session\SessionManager;

/**
 * The user's sessions: the active one (404 when none), one by id (404 when another user's), or
 * the latest ones. Each read first brings the session up to date with its runs.
 *
 * @implements ProviderInterface<Session>
 */
final class SessionProvider implements ProviderInterface
{
    use SessionIdTrait;

    public function __construct(
        private readonly SessionManager $sessions,
        private readonly SessionViewFactory $views,
        private readonly AuthenticatedUser $authenticatedUser,
    ) {
    }

    /**
     * @return Session|list<Session>|null
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Session|array|null
    {
        $user = $this->authenticatedUser->get();
        if ($operation instanceof CollectionOperationInterface) {
            return array_map($this->views->view(...), $this->sessions->recent($user, Session::RECENT_LIMIT));
        }
        if ('training_session_current' === $operation->getName()) {
            $session = $this->sessions->current($user);

            return null === $session ? null : $this->views->view($session);
        }

        try {
            return $this->views->view($this->sessions->get($user, self::sessionId($uriVariables)));
        } catch (SessionNotFoundException) {
            return null;
        }
    }
}
