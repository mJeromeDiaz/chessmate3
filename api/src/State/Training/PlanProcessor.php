<?php

declare(strict_types=1);

namespace App\State\Training;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Training\Plan;
use App\ApiResource\Training\PlanInput;
use App\Enum\Training\Module;
use App\Enum\Training\Repetition;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use App\Training\Exception\InvalidSessionException;
use App\Training\Exception\PlanLimitException;
use App\Training\Exception\PlanNotFoundException;
use App\Training\Plan\PlanManager;
use App\Training\Plan\PlanSettings;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * POST /training/plans, PUT and DELETE /training/plans/{id}. 422 for inconsistent settings or an
 * invalid step (its number in the message), 409 past the number of saved sessions allowed.
 *
 * @implements ProcessorInterface<PlanInput|null, Plan|null>
 */
final class PlanProcessor implements ProcessorInterface
{
    use PlanIdTrait;

    public function __construct(
        private readonly PlanManager $plans,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $trainingPlanWriteLimiter,
        private readonly ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?Plan
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->trainingPlanWriteLimiter, $user->getId()->toRfc4122());

        try {
            if ($operation instanceof Delete) {
                $this->plans->delete($user, self::planId($uriVariables));

                return null;
            }
            if (!$data instanceof PlanInput) {
                throw new \LogicException('A plan input is expected.');
            }
            $settings = self::settings($data);
            $plan = isset($uriVariables['id'])
                ? $this->plans->update($user, self::planId($uriVariables), $settings)
                : $this->plans->create($user, $settings);
        } catch (PlanNotFoundException) {
            throw new NotFoundHttpException('Saved session not found.');
        } catch (InvalidSessionException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage());
        } catch (PlanLimitException) {
            throw new ConflictHttpException('Too many saved sessions.');
        }

        return Plan::from($plan, $this->clock->now());
    }

    /**
     * @throws InvalidSessionException
     */
    private static function settings(PlanInput $data): PlanSettings
    {
        return new PlanSettings(
            title: trim($data->title),
            description: trim($data->description),
            steps: array_map(static fn ($step): array => [
                'module' => Module::from($step->module),
                'minutes' => $step->minutes,
                'notes' => trim($step->notes),
                'settings' => $step->settings,
            ], $data->steps),
            repetition: Repetition::from($data->repetition),
            time: $data->time,
            weekdays: $data->weekdays,
            public: $data->public,
            reminderEnabled: $data->reminderEnabled,
            reminderChannels: $data->reminderChannels,
            reminderMinutes: $data->reminderMinutes,
            calendarEnabled: $data->calendarEnabled,
        );
    }
}
