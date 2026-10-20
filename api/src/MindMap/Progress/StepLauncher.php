<?php

declare(strict_types=1);

namespace App\MindMap\Progress;

use App\Entity\MindMap\Attempt;
use App\Entity\MindMap\Progress;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\MindMap\Capacity;
use App\Enum\MindMap\StepKind;
use App\Enum\MindMap\StepStatus;
use App\MindMap\Path\Activity;
use App\MindMap\Path\Paths;
use App\MindMap\Path\PathState;
use App\Repository\MindMap\ProgressRepository;
use App\Training\Run\TimeboxRunner;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Plays and skips the steps of a path (docs/MIND_MAP.md, § 3 and 4). A step is played as a run of
 * its module ({@see TimeboxRunner}), judged when it closes ({@see PassRecorder}); a step done or
 * skipped may be played again (practice, or to pass a skipped exercise).
 */
final readonly class StepLauncher
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ProgressRepository $progress,
        private TimeboxRunner $runner,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws StepNotFoundException
     * @throws StepRefusedException
     * @throws \App\Training\Exception\RunInProgressException
     * @throws \App\Training\Exception\InvalidRunConfigException
     * @throws \App\Training\Exception\SubjectNotFoundException
     * @throws \App\Training\Exception\SubjectUnavailableException
     */
    public function start(User $user, Capacity $capacity, string $key): Run
    {
        $found = Paths::find($capacity, $key) ?? throw new StepNotFoundException();
        $step = $found['step'];
        $status = $this->statusOf($user, $capacity, $key);
        $activity = $step->activity;
        // Locked first: a step not reachable yet says so, whether its module exists or not.
        if (!\in_array($status, [StepStatus::Open, StepStatus::Soon, StepStatus::Done, StepStatus::Skipped], true)) {
            throw new StepRefusedException(StepRefusedException::LOCKED);
        }
        if (null === $activity || StepStatus::Soon === $status) {
            throw new StepRefusedException(StepRefusedException::SOON);
        }

        return $this->entityManager->getConnection()->transactional(function () use ($user, $capacity, $key, $activity): Run {
            $run = $this->runner->start($user, $activity->module, $this->subjectOf($user, $activity), $activity->budgetSeconds, $activity->config, null, \sprintf('%s:%s', $capacity->value, $key));
            $this->entityManager->persist(new Attempt($run, $capacity, $key, $run->getStartedAt()));
            $this->entityManager->flush();

            return $run;
        });
    }

    /**
     * Skips an exercise reachable now: what follows opens, nothing is earned.
     *
     * @throws StepNotFoundException
     * @throws StepRefusedException
     */
    public function skip(User $user, Capacity $capacity, string $key): void
    {
        $step = (Paths::find($capacity, $key) ?? throw new StepNotFoundException())['step'];
        if (StepKind::Exercise !== $step->kind) {
            throw new StepRefusedException(StepRefusedException::NOT_SKIPPABLE);
        }
        $refusal = $this->entityManager->getConnection()->transactional(function () use ($user, $capacity, $key): ?string {
            $now = $this->clock->now();
            $this->progress->lockPathOf($user);
            $rows = $this->progress->byStep($user, $capacity);
            $status = PathState::statuses(Paths::chapters($capacity), Progresses::state($rows))[$key] ?? StepStatus::Locked;
            if (StepStatus::Skipped === $status) {
                return null;
            }
            if (StepStatus::Done === $status) {
                return StepRefusedException::NOT_SKIPPABLE;
            }
            if (!\in_array($status, [StepStatus::Open, StepStatus::Soon], true)) {
                return StepRefusedException::LOCKED;
            }
            $row = $rows[$key] ?? new Progress($user, $capacity, $key, $now);
            $row->skip($now);
            $this->entityManager->persist($row);
            $this->entityManager->flush();

            return null;
        });
        if (null !== $refusal) {
            throw new StepRefusedException($refusal);
        }
    }

    private function statusOf(User $user, Capacity $capacity, string $key): StepStatus
    {
        $state = Progresses::state($this->progress->byStep($user, $capacity));

        return PathState::statuses(Paths::chapters($capacity), $state)[$key] ?? StepStatus::Locked;
    }

    private function subjectOf(User $user, Activity $activity): Uuid
    {
        return match ($activity->subject) {
            Activity::SUBJECT_USER => $user->getId(),
            default => throw new \LogicException(\sprintf('Unknown subject %s.', $activity->subject)),
        };
    }
}
