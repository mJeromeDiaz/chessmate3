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
use App\Guest\GuestPolicy;
use App\MindMap\Path\Activity;
use App\MindMap\Path\Chapter;
use App\MindMap\Path\PathState;
use App\Repository\MindMap\ProgressRepository;
use App\Training\Module\ModuleRegistry;
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
        private PathCatalog $catalog,
        private ModuleRegistry $modules,
        private GuestPolicy $guests,
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
        $found = $this->catalog->find($user, $capacity, $key) ?? throw new StepNotFoundException();
        $this->guests->assertChapter($user, $found['chapter']);
        $step = $found['step'];
        $rows = $this->progress->byStep($user, $capacity);
        $status = PathState::statuses($this->chaptersOf($user, $capacity, $found['chapter']), Progresses::state($rows))[$key] ?? StepStatus::Locked;
        $activity = $step->activity;
        // Locked first: a step not reachable yet says so, whether its module exists or not.
        if (!\in_array($status, [StepStatus::Open, StepStatus::Soon, StepStatus::Done, StepStatus::Skipped], true)) {
            throw new StepRefusedException(StepRefusedException::LOCKED);
        }
        if (null === $activity || StepStatus::Soon === $status) {
            throw new StepRefusedException(StepRefusedException::SOON);
        }

        $config = $activity->configFor(($rows[$key] ?? null)?->getPasses() ?? 0);
        if ($activity->prepared) {
            $config = $this->modules->for($activity->module)->prepare($user, $config, '')->config;
        }

        return $this->entityManager->getConnection()->transactional(function () use ($user, $capacity, $key, $activity, $config): Run {
            $run = $this->runner->start($user, $activity->module, $this->subjectOf($user, $activity), $activity->budgetSeconds, $config, null, \sprintf('%s:%s', $capacity->value, $key));
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
        $found = $this->catalog->find($user, $capacity, $key) ?? throw new StepNotFoundException();
        $this->guests->assertChapter($user, $found['chapter']);
        if (StepKind::Exercise !== $found['step']->kind) {
            throw new StepRefusedException(StepRefusedException::NOT_SKIPPABLE);
        }
        $chapters = $this->chaptersOf($user, $capacity, $found['chapter']);
        $refusal = $this->entityManager->getConnection()->transactional(function () use ($user, $capacity, $key, $chapters): ?string {
            $now = $this->clock->now();
            $this->progress->lockPathOf($user);
            $rows = $this->progress->byStep($user, $capacity);
            $status = PathState::statuses($chapters, Progresses::state($rows))[$key] ?? StepStatus::Locked;
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

    /**
     * The chapters a step's status is read from: its own when free (whatever came before, it
     * locks nothing), the whole path otherwise.
     *
     * @return list<Chapter>
     */
    private function chaptersOf(User $user, Capacity $capacity, Chapter $chapter): array
    {
        return $chapter->free ? [$chapter] : $this->catalog->chapters($user, $capacity);
    }

    private function subjectOf(User $user, Activity $activity): Uuid
    {
        return match ($activity->subject) {
            Activity::SUBJECT_USER => $user->getId(),
            default => throw new \LogicException(\sprintf('Unknown subject %s.', $activity->subject)),
        };
    }
}
