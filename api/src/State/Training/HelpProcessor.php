<?php

declare(strict_types=1);

namespace App\State\Training;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Training\HelpInput;
use App\ApiResource\Training\ItemHelp;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use App\Training\Exception\AidRefusedException;
use App\Training\Exception\ItemAlreadySubmittedException;
use App\Training\Exception\ItemNotFoundException;
use App\Training\Exception\RunClosedException;
use App\Training\Exception\RunNotFoundException;
use App\Training\Exception\SubmissionTooLateException;
use App\Training\Run\TimeboxRunner;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * POST /training/runs/{id}/help {itemId} (docs/PUZZLE_STREAK.md), POST
 * /training/runs/{id}/pardon {itemId} (docs/BLINDFOLD.md) and POST /training/runs/{id}/ready
 * {itemId} (docs/CALCULATION.md): 404 for an unknown run or item, 409 when
 * the run is over or its time up, the item already submitted (help, ready), or the aid refused (`detail`:
 * `no_help`, `help_limit`, `no_pardon`, `not_pardonable`, `too_late`, `not_offered`).
 *
 * @implements ProcessorInterface<HelpInput, ItemHelp>
 */
final class HelpProcessor implements ProcessorInterface
{
    use RunIdTrait;

    public const PARDON = 'training_run_pardon';
    public const READY = 'training_run_ready';

    public function __construct(
        private readonly TimeboxRunner $runner,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $trainingItemSubmitLimiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ItemHelp
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->trainingItemSubmitLimiter, $user->getId()->toRfc4122());
        $runId = self::runId($uriVariables);
        try {
            $answer = match ($operation->getName()) {
                self::PARDON => $this->runner->pardon($user, $runId, $data->itemId),
                self::READY => $this->runner->ready($user, $runId, $data->itemId),
                default => $this->runner->help($user, $runId, $data->itemId),
            };
        } catch (RunNotFoundException) {
            throw new NotFoundHttpException('Run not found.');
        } catch (ItemNotFoundException) {
            throw new NotFoundHttpException('Item not found in this run.');
        } catch (RunClosedException) {
            throw new ConflictHttpException('This run is over.');
        } catch (SubmissionTooLateException) {
            throw new ConflictHttpException('Time was up.');
        } catch (ItemAlreadySubmittedException) {
            throw new ConflictHttpException('Item already submitted.');
        } catch (AidRefusedException $e) {
            throw new ConflictHttpException($e->reason);
        }

        return ItemHelp::of($runId->toRfc4122(), $data->itemId, $answer);
    }
}
