<?php

declare(strict_types=1);

namespace App\State\EarlyAccess;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\EarlyAccess\InvitationKey;
use App\Entity\EarlyAccess\InvitationKey as InvitationKeyEntity;
use App\Enum\EarlyAccess\InvitationStatus;
use App\Repository\EarlyAccess\InvitationKeyRepository;
use App\Repository\EarlyAccess\InvitationLogRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /admin/invitation-keys?status=&email=&createdAfter=&createdBefore= (paginated) and
 * GET /admin/invitation-keys/{id} (with its log).
 *
 * @implements ProviderInterface<InvitationKey>
 */
final class InvitationProvider implements ProviderInterface
{
    use InvitationIdTrait;

    public function __construct(
        private readonly InvitationKeyRepository $invitations,
        private readonly InvitationLogRepository $logs,
        private readonly Pagination $pagination,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return TraversablePaginator<InvitationKey>|InvitationKey
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator|InvitationKey
    {
        $now = $this->clock->now();
        if (!$operation instanceof CollectionOperationInterface) {
            $invitation = $this->invitations->find(self::invitationId($uriVariables))
                ?? throw new NotFoundHttpException('Invitation not found.');

            return InvitationKey::from($invitation, $now, logs: $this->logs->findForInvitation($invitation));
        }

        $filters = \is_array($context['filters'] ?? null) ? $context['filters'] : [];
        $status = null;
        if (\is_string($filters['status'] ?? null) && '' !== $filters['status']) {
            $status = InvitationStatus::tryFrom($filters['status']) ?? throw new BadRequestHttpException('Unknown status.');
        }
        $email = \is_string($filters['email'] ?? null) ? trim($filters['email']) : null;

        $page = $this->pagination->getPage($context);
        $limit = $this->pagination->getLimit($operation, $context);
        $query = $this->invitations
            ->createListQueryBuilder($now, $status, $email, self::instant($filters, 'createdAfter'), self::instant($filters, 'createdBefore'))
            ->setFirstResult($this->pagination->getOffset($operation, $context))
            ->setMaxResults($limit)
            ->getQuery();
        /** @var Paginator<InvitationKeyEntity> $paginator */
        $paginator = new Paginator($query, fetchJoinCollection: false);

        $items = [];
        foreach ($paginator as $invitation) {
            $items[] = InvitationKey::from($invitation, $now);
        }

        return new TraversablePaginator(new \ArrayIterator($items), $page, $limit, \count($paginator));
    }

    /**
     * @param array<mixed> $filters
     */
    private static function instant(array $filters, string $name): ?\DateTimeImmutable
    {
        $value = $filters[$name] ?? null;
        if (!\is_string($value) || '' === $value) {
            return null;
        }
        // Strict ISO 8601 (as Date.toISOString() writes it, or with an offset): no free-form
        // strings, whose parse errors surface as exceptions Xdebug cannot decorate.
        foreach (['!Y-m-d\\TH:i:sP', '!Y-m-d\\TH:i:s.vP'] as $format) {
            $instant = \DateTimeImmutable::createFromFormat($format, $value);
            $errors = \DateTimeImmutable::getLastErrors();
            if (false !== $instant && (false === $errors || 0 === $errors['warning_count'] + $errors['error_count'])) {
                return $instant->setTimezone(new \DateTimeZone('UTC'));
            }
        }

        throw new BadRequestHttpException(\sprintf('%s is not an ISO 8601 instant.', $name));
    }
}
