<?php

declare(strict_types=1);

namespace App\State\EarlyAccess;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\EarlyAccess\Player;
use App\EarlyAccess\Stats\PlayerFacts;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Psr\Clock\ClockInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * GET /admin/users?search=&suspended= (paginated, newest first; admins only, rate limited per admin:
 * admin_read).
 *
 * @implements ProviderInterface<Player>
 */
final class PlayerProvider implements ProviderInterface
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PlayerFacts $playerFacts,
        private readonly Pagination $pagination,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $adminReadLimiter,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return TraversablePaginator<Player>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator
    {
        $this->rateLimitGuard->consume($this->adminReadLimiter, $this->authenticatedUser->get()->getId()->toRfc4122());
        $filters = \is_array($context['filters'] ?? null) ? $context['filters'] : [];
        $search = \is_string($filters['search'] ?? null) ? trim($filters['search']) : '';

        $qb = $this->users->createQueryBuilder('u')
            ->orderBy('u.createdAt', 'DESC')
            ->addOrderBy('u.id', 'DESC');
        if ('' !== $search) {
            $qb->andWhere('u.email LIKE :search OR u.handle LIKE :search OR u.displayName LIKE :search')
                ->setParameter('search', '%'.addcslashes($search, '%_\\').'%');
        }
        $suspended = filter_var($filters['suspended'] ?? null, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE);
        if (true === $suspended) {
            $qb->andWhere('u.suspendedAt IS NOT NULL');
        } elseif (false === $suspended) {
            $qb->andWhere('u.suspendedAt IS NULL');
        }
        $page = $this->pagination->getPage($context);
        $limit = $this->pagination->getLimit($operation, $context);
        $query = $qb->setFirstResult($this->pagination->getOffset($operation, $context))->setMaxResults($limit)->getQuery();
        /** @var Paginator<User> $paginator */
        $paginator = new Paginator($query, fetchJoinCollection: false);

        $users = iterator_to_array($paginator, false);
        $now = $this->clock->now();
        $facts = $this->playerFacts->forUsers($users, $now);
        $items = array_map(static fn (User $user): Player => Player::from($user, $facts[$user->getId()->toRfc4122()], $now), $users);

        return new TraversablePaginator(new \ArrayIterator($items), $page, $limit, \count($paginator));
    }
}
