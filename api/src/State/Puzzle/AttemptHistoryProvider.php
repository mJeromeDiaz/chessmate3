<?php

declare(strict_types=1);

namespace App\State\Puzzle;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Puzzle\Attempt;
use App\Entity\Puzzle\Attempt as AttemptEntity;
use App\Enum\Puzzle\AttemptStatus;
use App\Repository\Puzzle\AttemptRepository;
use App\Security\AuthenticatedUser;
use Doctrine\ORM\Tools\Pagination\Paginator;

/**
 * GET /puzzles/attempts?result=solved|failed&theme=fork: the current user's history, paginated.
 *
 * @implements ProviderInterface<Attempt>
 */
final class AttemptHistoryProvider implements ProviderInterface
{
    public function __construct(
        private readonly AttemptRepository $attempts,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly Pagination $pagination,
    ) {
    }

    /**
     * @return TraversablePaginator<Attempt>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator
    {
        $filters = \is_array($context['filters'] ?? null) ? $context['filters'] : [];
        $result = \is_string($filters['result'] ?? null) ? AttemptStatus::tryFrom($filters['result']) : null;
        if (AttemptStatus::Pending === $result) {
            $result = null;
        }
        $theme = \is_string($filters['theme'] ?? null) && '' !== $filters['theme'] ? $filters['theme'] : null;

        $page = $this->pagination->getPage($context);
        $limit = $this->pagination->getLimit($operation, $context);
        $offset = $this->pagination->getOffset($operation, $context);

        $query = $this->attempts->createHistoryQueryBuilder($this->authenticatedUser->get(), $result, $theme)
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery();
        /** @var Paginator<AttemptEntity> $paginator */
        $paginator = new Paginator($query, fetchJoinCollection: false);

        $items = [];
        foreach ($paginator as $attempt) {
            $items[] = Attempt::from($attempt);
        }

        return new TraversablePaginator(new \ArrayIterator($items), $page, $limit, \count($paginator));
    }
}
