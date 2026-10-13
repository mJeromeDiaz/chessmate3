<?php

declare(strict_types=1);

namespace App\State\Training;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Training\Run;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use App\Training\Exception\ExtensionRefusedException;
use App\Training\Exception\RunClosedException;
use App\Training\Exception\RunNotFoundException;
use App\Training\Run\TimeboxRunner;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * POST /training/runs/{id}/extend (overtime, docs/TRAINING.md): 409 when the run is over or not
 * waiting for the player's decision.
 *
 * @implements ProcessorInterface<mixed, Run>
 */
final class ExtendRunProcessor implements ProcessorInterface
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

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Run
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->trainingItemNextLimiter, $user->getId()->toRfc4122());
        try {
            return Run::from($this->runner->extend($user, self::runId($uriVariables)), $this->clock->now());
        } catch (RunNotFoundException) {
            throw new NotFoundHttpException('Run not found.');
        } catch (RunClosedException) {
            throw new ConflictHttpException('This run is over.');
        } catch (ExtensionRefusedException) {
            throw new ConflictHttpException('This run is not waiting for a decision.');
        }
    }
}
