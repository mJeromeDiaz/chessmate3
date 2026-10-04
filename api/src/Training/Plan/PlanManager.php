<?php

declare(strict_types=1);

namespace App\Training\Plan;

use App\Entity\Training\Plan;
use App\Entity\Training\Session;
use App\Entity\User;
use App\Repository\Training\PlanRepository;
use App\Training\Exception\InvalidSessionException;
use App\Training\Exception\PlanLimitException;
use App\Training\Exception\PlanNotFoundException;
use App\Training\Exception\SessionInProgressException;
use App\Training\Session\SessionManager;
use App\Training\Session\StepChecker;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Saved sessions (docs/TRAINING.md): create, change, delete, launch. Their steps are checked by
 * the modules (settings, subjects that exist); whether they can be played now only at a launch.
 * Changing or deleting a plan never touches the sessions already launched from it.
 */
final class PlanManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlanRepository $plans,
        private readonly StepChecker $checker,
        private readonly SessionManager $sessions,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws InvalidSessionException
     * @throws PlanLimitException
     */
    public function create(User $user, PlanSettings $settings): Plan
    {
        $this->checker->check($user, $settings->steps, false);
        if ($this->plans->countByUser($user) >= Plan::MAX_PER_USER) {
            throw new PlanLimitException();
        }
        $plan = new Plan($user, $this->now());
        $this->apply($plan, $settings);
        $this->entityManager->persist($plan);
        $this->entityManager->flush();

        return $plan;
    }

    /**
     * @throws PlanNotFoundException
     * @throws InvalidSessionException
     */
    public function update(User $user, Uuid $id, PlanSettings $settings): Plan
    {
        $plan = $this->plans->findOwned($id, $user) ?? throw new PlanNotFoundException();
        $this->checker->check($user, $settings->steps, false);
        $this->apply($plan, $settings);
        $this->entityManager->flush();

        return $plan;
    }

    /**
     * The sessions launched from it stay (their link is set to NULL by the database).
     *
     * @throws PlanNotFoundException
     */
    public function delete(User $user, Uuid $id): void
    {
        $plan = $this->plans->findOwned($id, $user) ?? throw new PlanNotFoundException();
        $this->entityManager->remove($plan);
        $this->entityManager->flush();
    }

    /**
     * Launches a played session from the plan (its first step is started apart).
     *
     * @throws PlanNotFoundException
     * @throws InvalidSessionException    a step cannot be played now
     * @throws SessionInProgressException
     */
    public function launch(User $user, Uuid $id): Session
    {
        $plan = $this->plans->findOwned($id, $user) ?? throw new PlanNotFoundException();

        return $this->sessions->create($user, $plan->getTitle(), $plan->getDescription(), $plan->sessionSteps(), $plan);
    }

    private function apply(Plan $plan, PlanSettings $s): void
    {
        $plan->update(
            $s->title,
            $s->description,
            $s->steps,
            $s->repetition,
            $s->time,
            $s->weekdays,
            $s->public,
            $s->reminderEnabled,
            $s->reminderChannels,
            $s->reminderMinutes,
            $s->calendarEnabled,
            $this->now(),
        );
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
    }
}
