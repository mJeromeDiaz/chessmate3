<?php

declare(strict_types=1);

namespace App\Repository\Shop;

use App\Entity\Shop\Slide;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Slide>
 */
class SlideRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Slide::class);
    }

    /**
     * Every slide, by position.
     *
     * @return list<Slide>
     */
    public function allInOrder(): array
    {
        /** @var list<Slide> */
        return $this->findBy([], ['position' => 'ASC']);
    }

    /**
     * The carousel at $now: shown slides whose offer is on sale.
     *
     * @return list<Slide>
     */
    public function shown(\DateTimeImmutable $now): array
    {
        return array_values(array_filter($this->allInOrder(), static fn (Slide $slide): bool => $slide->isShown($now)));
    }
}
