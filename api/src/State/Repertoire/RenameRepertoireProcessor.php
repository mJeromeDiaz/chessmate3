<?php

declare(strict_types=1);

namespace App\State\Repertoire;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Repertoire\RenameRepertoireInput;
use App\ApiResource\Repertoire\Repertoire;
use App\Repertoire\Exception\RepertoireNotFoundException;
use App\Repertoire\RepertoireManager;
use App\Repository\Repertoire\SegmentRepository;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * POST /repertoires/{id}/rename.
 *
 * @implements ProcessorInterface<RenameRepertoireInput, Repertoire>
 */
final class RenameRepertoireProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly RepertoireManager $manager,
        private readonly SegmentRepository $segments,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $repertoireEditLimiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Repertoire
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->repertoireEditLimiter, $user->getId()->toRfc4122());
        $id = RepertoireIds::fromUri($uriVariables, 'id') ?? throw new NotFoundHttpException();

        try {
            $repertoire = $this->manager->rename($user, $id, $data->name);
        } catch (RepertoireNotFoundException) {
            throw new NotFoundHttpException('Repertoire not found.');
        } catch (\InvalidArgumentException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        return Repertoire::from($repertoire, $this->segments->countPresentableByRepertoire($user)[$id->toRfc4122()] ?? 0);
    }
}
