<?php

declare(strict_types=1);

namespace App\Repository\Puzzle;

use App\Entity\Puzzle\Theme;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Theme>
 */
class ThemeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Theme::class);
    }

    /**
     * @return list<Theme>
     */
    public function findAllOrdered(): array
    {
        /** @var list<Theme> */
        return $this->createQueryBuilder('t')->orderBy('t.position', 'ASC')->getQuery()->getResult();
    }

    /**
     * @param list<string> $keys
     *
     * @return list<Theme>
     */
    public function findByKeys(array $keys): array
    {
        if ([] === $keys) {
            return [];
        }

        /** @var list<Theme> */
        return $this->createQueryBuilder('t')
            ->where('t.key IN (:keys)')
            ->setParameter('keys', $keys)
            ->orderBy('t.position', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
