<?php

declare(strict_types=1);

namespace App\State\EarlyAccess;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\EarlyAccess\AccessRequest;
use App\Entity\EarlyAccess\AccessRequest as AccessRequestEntity;
use App\Repository\EarlyAccess\AccessRequestRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * GET /admin/access-requests?invited= (paginated, in order of arrival).
 *
 * @implements ProviderInterface<AccessRequest>
 */
final class AccessRequestProvider implements ProviderInterface
{
    public function __construct(
        private readonly AccessRequestRepository $requests,
        private readonly Pagination $pagination,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return TraversablePaginator<AccessRequest>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator
    {
        $filters = \is_array($context['filters'] ?? null) ? $context['filters'] : [];
        $invited = match ($filters['invited'] ?? null) {
            null, '' => null,
            'true', '1' => true,
            'false', '0' => false,
            default => throw new BadRequestHttpException('invited is true or false.'),
        };

        $page = $this->pagination->getPage($context);
        $limit = $this->pagination->getLimit($operation, $context);
        $query = $this->requests->createListQueryBuilder($invited)
            ->setFirstResult($this->pagination->getOffset($operation, $context))
            ->setMaxResults($limit)
            ->getQuery();
        /** @var Paginator<AccessRequestEntity> $paginator */
        $paginator = new Paginator($query, fetchJoinCollection: false);
        $rows = iterator_to_array($paginator, false);
        $withAccount = $this->requests->findEmailsWithAccount(array_map(static fn (AccessRequestEntity $r): string => $r->getEmail(), $rows));

        $now = $this->clock->now();
        $items = array_map(static fn (AccessRequestEntity $r): AccessRequest => AccessRequest::from($r, $now, isset($withAccount[$r->getEmail()])), $rows);

        return new TraversablePaginator(new \ArrayIterator($items), $page, $limit, \count($paginator));
    }
}
