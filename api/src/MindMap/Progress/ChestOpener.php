<?php

declare(strict_types=1);

namespace App\MindMap\Progress;

use App\Entity\MindMap\Chest;
use App\Entity\User;
use App\Repository\MindMap\ChestRepository;
use App\Shop\Wallet\Wallets;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Opens the chests of the paths (docs/MIND_MAP.md, § 6): their crystals are already credited,
 * opening only marks them seen.
 */
final readonly class ChestOpener
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ChestRepository $chests,
        private Wallets $wallets,
    ) {
    }

    /**
     * @return array{chest: Chest, balance: int}|null null when it is not the player's
     */
    public function open(User $user, Uuid $id, \DateTimeImmutable $now): ?array
    {
        $chest = $this->chests->findOwned($id, $user);
        if (null === $chest) {
            return null;
        }
        $chest->open($now);
        $this->entityManager->flush();

        return ['chest' => $chest, 'balance' => $this->wallets->balance($user->getId())];
    }
}
