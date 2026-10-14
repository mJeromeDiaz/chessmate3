<?php

declare(strict_types=1);

namespace App\Repository\Shop;

use App\Entity\Shop\Offer;
use App\Enum\Shop\Section;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Offer>
 */
class OfferRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Offer::class);
    }

    /**
     * Every offer, in the store's order (section, then position).
     *
     * @return list<Offer>
     */
    public function allInOrder(): array
    {
        /** @var list<Offer> $offers */
        $offers = $this->findBy([], ['position' => 'ASC', 'name' => 'ASC']);
        $sections = array_flip(array_map(static fn (Section $s): string => $s->value, Section::cases()));
        usort($offers, static fn (Offer $a, Offer $b): int => $sections[$a->getSection()->value] <=> $sections[$b->getSection()->value]);

        return $offers;
    }

    /**
     * The offers the store shows at $now.
     *
     * @return list<Offer>
     */
    public function listed(\DateTimeImmutable $now): array
    {
        return array_values(array_filter($this->allInOrder(), static fn (Offer $offer): bool => $offer->isListed($now)));
    }
}
