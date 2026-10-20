<?php

declare(strict_types=1);

namespace App\State\MindMap;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MindMap\Outcome;
use App\MindMap\Path\Paths;
use App\MindMap\Progress\PathReader;
use App\Repository\MindMap\AttemptRepository;
use App\Repository\MindMap\ChestRepository;
use App\Repository\MindMap\ProgressRepository;
use App\Security\AuthenticatedUser;
use App\State\Training\RunIdTrait;
use App\Training\Run\TimeboxRunner;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /mind-map/runs/{id}: the verdict on the run of a step (a run past its time is closed, hence
 * judged, first).
 *
 * @implements ProviderInterface<Outcome>
 */
final class OutcomeProvider implements ProviderInterface
{
    use RunIdTrait;

    public function __construct(
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly AttemptRepository $attempts,
        private readonly ProgressRepository $progress,
        private readonly ChestRepository $chests,
        private readonly PathReader $reader,
        private readonly TimeboxRunner $runner,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Outcome
    {
        $runId = self::runId($uriVariables);
        $user = $this->authenticatedUser->get();
        $this->runner->closeExpired($user);
        $attempt = $this->attempts->findOwnedByRun($runId, $user);
        $found = null === $attempt ? null : Paths::find($attempt->getCapacity(), $attempt->getStep());
        if (null === $attempt || null === $found) {
            throw new NotFoundHttpException('Run not found.');
        }
        $step = $found['step'];
        $capacity = $attempt->getCapacity();

        $outcome = new Outcome();
        $outcome->id = $runId->toRfc4122();
        $outcome->capacity = $capacity->value;
        $outcome->step = $step->key;
        $outcome->kind = $step->kind->value;
        $outcome->outcome = $attempt->getOutcome()->value;
        $outcome->itemCount = $attempt->getItemCount();
        $outcome->successCount = $attempt->getSuccessCount();
        $outcome->failures = $attempt->getFailures();
        $outcome->count = $step->activity->count ?? 0;
        $outcome->lives = $step->activity?->lives;
        $outcome->passes = ($this->progress->byStep($user, $capacity)[$step->key] ?? null)?->getPasses() ?? 0;
        $outcome->required = $step->passes;
        $outcome->completed = $attempt->isCompleted();
        if ($outcome->completed) {
            $outcome->xp = $step->xp;
            $chest = $this->chests->byStep($user, $capacity)[$step->key] ?? null;
            $outcome->chest = null === $chest ? null : PathReader::chestView($chest);
        }
        $outcome->unopenedChests = $this->reader->unopenedChests($user, $capacity);

        return $outcome;
    }
}
