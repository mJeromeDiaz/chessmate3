<?php

declare(strict_types=1);

namespace App\State\Repertoire;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Repertoire\PendingList;
use App\Repertoire\Auto\PendingNotOpenException;
use App\Repertoire\Auto\PendingService;
use App\Repertoire\Exception\RepertoireNotFoundException;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * POST /repertoires/{id}/pending/{pendingId}/dismiss and /restore: the list afterwards. 404 unknown
 * repertoire or pending position; 409 pending_not_open; 429.
 *
 * @implements ProcessorInterface<null, PendingList>
 */
final class PendingProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly PendingService $pendings,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactoryInterface $repertoireEditLimiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PendingList
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->repertoireEditLimiter, $user->getId()->toRfc4122());
        $id = RepertoireIds::fromUri($uriVariables, 'id') ?? throw new NotFoundHttpException();
        $pendingId = RepertoireIds::fromUri($uriVariables, 'pendingId') ?? throw new NotFoundHttpException();

        try {
            match ($operation->getName()) {
                'repertoire_pending_dismiss' => $this->pendings->dismiss($user, $id, $pendingId),
                'repertoire_pending_restore' => $this->pendings->restore($user, $id, $pendingId),
                default => throw new \LogicException('Unexpected operation.'),
            };

            return PendingProvider::view($id, $this->pendings->list($user, $id));
        } catch (RepertoireNotFoundException) {
            throw new NotFoundHttpException('Pending position not found.');
        } catch (PendingNotOpenException $e) {
            throw new ConflictHttpException('pending_not_open', $e);
        }
    }
}
