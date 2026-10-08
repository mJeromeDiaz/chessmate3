<?php

declare(strict_types=1);

namespace App\State\Evaluation;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Evaluation\AdminPosition;
use App\Entity\Evaluation\Position;
use App\Enum\Evaluation\PositionTag;
use App\Repository\Evaluation\PositionRepository;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Uid\Uuid;

/**
 * GET /admin/evaluation/positions?active=&tag= (paginated) and GET /admin/evaluation/positions/{id},
 * within the admins' read budget.
 *
 * @implements ProviderInterface<AdminPosition>
 */
final class AdminPositionProvider implements ProviderInterface
{
    public function __construct(
        private readonly PositionRepository $positions,
        private readonly Pagination $pagination,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $adminReadLimiter,
    ) {
    }

    /**
     * @return TraversablePaginator<AdminPosition>|AdminPosition
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator|AdminPosition
    {
        $this->rateLimitGuard->consume($this->adminReadLimiter, $this->authenticatedUser->get()->getId()->toRfc4122());
        if (!$operation instanceof CollectionOperationInterface) {
            $id = $uriVariables['id'] ?? null;
            $position = \is_string($id) && Uuid::isValid($id) ? $this->positions->find(Uuid::fromString($id)) : null;
            if (null === $position) {
                throw new NotFoundHttpException('Position not found.');
            }

            return AdminPosition::from($position, $this->positions->playCounts([$position])[$position->getId()->toRfc4122()] ?? 0);
        }

        $filters = \is_array($context['filters'] ?? null) ? $context['filters'] : [];
        $active = match ($filters['active'] ?? null) {
            'true', '1' => true,
            'false', '0' => false,
            null, '' => null,
            default => throw new BadRequestHttpException('active must be true or false.'),
        };
        $tag = null;
        if (\is_string($filters['tag'] ?? null) && '' !== $filters['tag']) {
            $tag = PositionTag::tryFrom($filters['tag']) ?? throw new BadRequestHttpException('Unknown tag.');
        }

        $page = $this->pagination->getPage($context);
        $limit = $this->pagination->getLimit($operation, $context);
        $query = $this->positions->createListQueryBuilder($active, $tag)
            ->setFirstResult($this->pagination->getOffset($operation, $context))
            ->setMaxResults($limit)
            ->getQuery();
        /** @var Paginator<Position> $paginator */
        $paginator = new Paginator($query, fetchJoinCollection: false);
        $positions = iterator_to_array($paginator, false);
        $played = $this->positions->playCounts($positions);
        $items = array_map(static fn (Position $position): AdminPosition => AdminPosition::from($position, $played[$position->getId()->toRfc4122()] ?? 0), $positions);

        return new TraversablePaginator(new \ArrayIterator($items), $page, $limit, \count($paginator));
    }
}
