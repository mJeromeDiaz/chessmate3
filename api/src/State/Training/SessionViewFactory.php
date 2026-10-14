<?php

declare(strict_types=1);

namespace App\State\Training;

use App\ApiResource\Training\Session;
use App\Entity\Training\Session as SessionEntity;
use App\Repository\Training\RunRepository;
use App\Training\Session\SessionRewardInterface;

/**
 * Builds the API view of a session, with the recap of the runs of its steps and its crowns.
 */
final class SessionViewFactory
{
    public function __construct(
        private readonly RunRepository $runs,
        private readonly SessionRewardInterface $rewards,
    ) {
    }

    public function view(SessionEntity $session): Session
    {
        return Session::from($session, $this->runs->findByParent($session->getId()), $this->rewards->viewsOf([$session])[$session->getId()->toRfc4122()] ?? null);
    }

    /**
     * @param list<SessionEntity> $sessions
     *
     * @return list<Session>
     */
    public function views(array $sessions): array
    {
        $rewards = $this->rewards->viewsOf($sessions);

        return array_map(fn (SessionEntity $session): Session => Session::from(
            $session,
            $this->runs->findByParent($session->getId()),
            $rewards[$session->getId()->toRfc4122()] ?? null,
        ), $sessions);
    }
}
