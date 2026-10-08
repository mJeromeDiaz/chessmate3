<?php

declare(strict_types=1);

namespace App\State\Evaluation;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Evaluation\AdminPosition;
use App\ApiResource\Evaluation\PositionInput;
use App\Evaluation\Position\PositionEditor;
use App\Evaluation\Position\PositionRefusedException;
use App\Repository\Evaluation\PositionRepository;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Uid\Uuid;

/**
 * POST, PUT and DELETE /admin/evaluation/positions[/{id}], within the admins' write budget: 422
 * for an illegal FEN or one with no move to play, 409 for a FEN already in the catalogue or the
 * deletion of a position already played.
 *
 * @implements ProcessorInterface<PositionInput|null, AdminPosition|null>
 */
final class AdminPositionProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly PositionEditor $editor,
        private readonly PositionRepository $positions,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $adminWriteLimiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?AdminPosition
    {
        $this->rateLimitGuard->consume($this->adminWriteLimiter, $this->authenticatedUser->get()->getId()->toRfc4122());
        $existing = null;
        if (isset($uriVariables['id'])) {
            $id = $uriVariables['id'];
            $existing = \is_string($id) && Uuid::isValid($id) ? $this->positions->find(Uuid::fromString($id)) : null;
            if (null === $existing) {
                throw new NotFoundHttpException('Position not found.');
            }
        }

        try {
            if ($operation instanceof DeleteOperationInterface) {
                if (null !== $existing) {
                    $this->editor->delete($existing);
                }

                return null;
            }
            if (!$data instanceof PositionInput) {
                throw new UnprocessableEntityHttpException('A position is expected.');
            }
            $position = null === $existing ? $this->editor->create($data) : $this->editor->update($existing, $data);
        } catch (PositionRefusedException $e) {
            throw \in_array($e->reason, ['duplicate', 'played'], true) ? new ConflictHttpException($e->getMessage()) : new UnprocessableEntityHttpException($e->getMessage());
        }

        return AdminPosition::from($position, $this->positions->playCounts([$position])[$position->getId()->toRfc4122()] ?? 0);
    }
}
