<?php

declare(strict_types=1);

namespace App\Repository\Repertoire;

use App\Entity\Repertoire\Repertoire;
use App\Entity\User;
use App\Enum\Repertoire\Color;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Repertoire>
 */
class RepertoireRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Repertoire::class);
    }

    /**
     * The user's repertoire, or null (also for another user's: never tell them apart).
     */
    public function findOwned(Uuid $id, User $user): ?Repertoire
    {
        return $this->findOneBy(['id' => $id, 'user' => $user]);
    }

    /**
     * The user's repertoire under SELECT ... FOR UPDATE (inside a transaction): serialises every
     * change of one repertoire. Always locked before its positions, moves and segments.
     */
    public function lockOwned(Uuid $id, User $user): ?Repertoire
    {
        $repertoire = $this->findOwned($id, $user);
        if (null !== $repertoire) {
            $this->getEntityManager()->refresh($repertoire, LockMode::PESSIMISTIC_WRITE);
        }

        return $repertoire;
    }

    /**
     * @return list<Repertoire> newest first (UUID v7 ids: creation order within a second)
     */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * The user's newest repertoire of this colour with a position named after this opening family
     * (an opening name of lichess-org/chess-openings, its part before ":": "Italian Game").
     */
    public function findByFamily(User $user, Color $color, string $family): ?Repertoire
    {
        $id = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT r.id FROM repertoire r
             WHERE r.user_id = ? AND r.color = ? AND EXISTS (
                 SELECT 1 FROM repertoire_position p
                 JOIN repertoire_opening o ON o.epd_hash = p.fen_hash
                 WHERE p.repertoire_id = r.id AND (o.name = ? OR LEFT(o.name, CHAR_LENGTH(?) + 1) = CONCAT(?, \':\'))
             )
             ORDER BY r.created_at DESC, r.id DESC LIMIT 1',
            [$user->getId()->toBinary(), $color->value, $family, $family, $family],
            [ParameterType::BINARY, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING],
        );

        return \is_string($id) ? $this->find(Uuid::fromBinary($id)) : null;
    }

    public function countByUser(User $user): int
    {
        return $this->count(['user' => $user]);
    }
}
