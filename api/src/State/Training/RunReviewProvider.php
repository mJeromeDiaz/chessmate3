<?php

declare(strict_types=1);

namespace App\State\Training;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Training\RunReview;
use App\Repository\Gamification\XpEntryRepository;
use App\Security\AuthenticatedUser;
use App\Training\Exception\RunNotFoundException;
use App\Training\Module\ModuleRegistry;
use App\Training\Run\TimeboxRunner;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * GET /training/runs/{id}/review. A run past its time is closed first (lazy closing); a run still
 * active has no review yet (409). Free study reviews with no items.
 *
 * @implements ProviderInterface<RunReview>
 */
final class RunReviewProvider implements ProviderInterface
{
    use RunIdTrait;

    public function __construct(
        private readonly TimeboxRunner $runner,
        private readonly ModuleRegistry $modules,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly XpEntryRepository $xp,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?RunReview
    {
        try {
            $run = $this->runner->get($this->authenticatedUser->get(), self::runId($uriVariables));
        } catch (RunNotFoundException) {
            return null;
        }
        if ($run->isActive()) {
            throw new ConflictHttpException('The run is still in progress.');
        }

        $review = RunReview::from($run, $this->modules->reviewer($run->getModule())?->review($run) ?? []);
        $review->xp = $this->xp->sumForRun($run->getId());

        return $review;
    }
}
