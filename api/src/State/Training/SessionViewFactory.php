<?php

declare(strict_types=1);

namespace App\State\Training;

use App\ApiResource\Training\Session;
use App\Entity\Training\Session as SessionEntity;
use App\Repository\Training\RunRepository;

/**
 * Builds the API view of a session, with the recap of the runs of its steps.
 */
final class SessionViewFactory
{
    public function __construct(
        private readonly RunRepository $runs,
    ) {
    }

    public function view(SessionEntity $session): Session
    {
        return Session::from($session, $this->runs->findByParent($session->getId()));
    }
}
