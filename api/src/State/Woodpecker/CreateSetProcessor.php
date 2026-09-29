<?php

declare(strict_types=1);

namespace App\State\Woodpecker;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Woodpecker\CreateSetInput;
use App\ApiResource\Woodpecker\Set;
use App\Enum\Woodpecker\SetMode;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use App\Woodpecker\Exception\NotEnoughPuzzlesException;
use App\Woodpecker\Exception\OngoingSetExistsException;
use App\Woodpecker\Light\GrowthPolicy;
use App\Woodpecker\Set\LightConfig;
use App\Woodpecker\Set\SetConfig;
use App\Woodpecker\Set\SetManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * POST /woodpecker/sets. 409 while another set of the same mode is active or paused, 422 when too
 * few puzzles match.
 *
 * @implements ProcessorInterface<CreateSetInput, Set>
 */
final class CreateSetProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly SetManager $manager,
        private readonly SetViewFactory $views,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $woodpeckerSetCreateLimiter,
        private readonly GrowthPolicy $growthPolicy,
        #[Autowire('%woodpecker.min_puzzles%')]
        private readonly int $minPuzzles,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Set
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->woodpeckerSetCreateLimiter, $user->getId()->toRfc4122());

        $light = SetMode::Light->value === $data->mode;
        if (!$light && $data->puzzleCount < $this->minPuzzles) {
            throw new UnprocessableEntityHttpException(sprintf('A set has at least %d puzzles.', $this->minPuzzles));
        }
        if (!$light && $data->minCycleDays > $data->firstCycleDays) {
            throw new UnprocessableEntityHttpException('The minimum cycle length exceeds the first cycle.');
        }
        [$ratingMin, $ratingMax] = null === $data->ratingMin || null === $data->ratingMax
            ? $this->manager->defaultRatingRange($user)
            : [$data->ratingMin, $data->ratingMax];

        $config = $light ? new LightConfig(
            puzzleCount: $this->growthPolicy->initialSize,
            ratingMin: $ratingMin,
            ratingMax: $ratingMax,
            themes: array_values(array_unique($data->themes)),
            shuffle: $data->shuffle,
        ) : new SetConfig(
            puzzleCount: $data->puzzleCount,
            ratingMin: $ratingMin,
            ratingMax: $ratingMax,
            themes: array_values(array_unique($data->themes)),
            cycleCount: $data->cycleCount,
            firstCycleDays: $data->firstCycleDays,
            reductionFactor: $data->reductionFactor,
            minCycleDays: $data->minCycleDays,
            restDays: $data->restDays,
            shuffle: $data->shuffle,
        );

        try {
            return $this->views->create($this->manager->create($user, trim($data->name), $config));
        } catch (OngoingSetExistsException) {
            throw new ConflictHttpException($light ? 'Another light set is active or paused.' : 'Another set is active or paused.');
        } catch (NotEnoughPuzzlesException $e) {
            throw new UnprocessableEntityHttpException(sprintf('Only %d puzzles match these criteria.', $e->found));
        } catch (\InvalidArgumentException) {
            throw new UnprocessableEntityHttpException('Unknown theme.');
        }
    }
}
