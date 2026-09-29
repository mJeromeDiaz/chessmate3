<?php

declare(strict_types=1);

namespace App\State\Training;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Training\Run;
use App\Security\AuthenticatedUser;
use App\Training\Exception\RunNotFoundException;
use App\Training\Run\TimeboxRunner;
use Psr\Clock\ClockInterface;

/**
 * GET /training/runs/current (404 when none) and /training/runs/{id}. A run past its time is
 * closed first (lazy closing).
 *
 * @implements ProviderInterface<Run>
 */
final class RunProvider implements ProviderInterface
{
    use RunIdTrait;

    public function __construct(
        private readonly TimeboxRunner $runner,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly ClockInterface $clock,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?Run
    {
        $user = $this->authenticatedUser->get();
        if ('training_run_current' === $operation->getName()) {
            $run = $this->runner->current($user);

            return null === $run ? null : Run::from($run, $this->clock->now());
        }

        try {
            return Run::from($this->runner->get($user, self::runId($uriVariables)), $this->clock->now());
        } catch (RunNotFoundException) {
            return null;
        }
    }
}
