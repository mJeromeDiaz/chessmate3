<?php

declare(strict_types=1);

namespace App\State\Repertoire;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Repertoire\CreateRepertoireInput;
use App\ApiResource\Repertoire\Repertoire;
use App\Enum\Repertoire\Color;
use App\Repertoire\Exception\LimitReachedException;
use App\Repertoire\RepertoireManager;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * POST /repertoires. 422 when the user has as many repertoires as allowed.
 *
 * @implements ProcessorInterface<CreateRepertoireInput, Repertoire>
 */
final class CreateRepertoireProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly RepertoireManager $manager,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $repertoireCreateLimiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Repertoire
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->repertoireCreateLimiter, $user->getId()->toRfc4122());

        try {
            return Repertoire::from($this->manager->create($user, $data->name, Color::from($data->color)), 0);
        } catch (LimitReachedException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        } catch (\InvalidArgumentException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }
    }
}
