<?php

declare(strict_types=1);

namespace App\State\Training;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Training\RunStep;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use App\Training\Exception\RunNotFoundException;
use App\Training\Run\TimeboxRunner;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * POST /training/runs/{id}/next: 200 with the item, or without one once the run is closed (time
 * up, subject done): the closed run carries its recap.
 *
 * @implements ProcessorInterface<mixed, RunStep>
 */
final class NextItemProcessor implements ProcessorInterface
{
    use RunIdTrait;

    public function __construct(
        private readonly TimeboxRunner $runner,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $trainingItemNextLimiter,
        private readonly ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RunStep
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->trainingItemNextLimiter, $user->getId()->toRfc4122());

        try {
            return RunStep::from($this->runner->next($user, self::runId($uriVariables)), $this->clock->now());
        } catch (RunNotFoundException) {
            throw new NotFoundHttpException('Run not found.');
        }
    }
}
