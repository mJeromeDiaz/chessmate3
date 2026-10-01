<?php

declare(strict_types=1);

namespace App\Repertoire;

use App\Chess\Position\PositionKey;
use App\Chess\Rules;
use App\Entity\Repertoire\Position;
use App\Entity\Repertoire\Repertoire;
use App\Entity\User;
use App\Enum\Repertoire\Color;
use App\Repertoire\Exception\LimitReachedException;
use App\Repertoire\Exception\RepertoireNotFoundException;
use App\Repository\Repertoire\RepertoireRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Creates, renames and deletes repertoires (their graph: App\Repertoire\Graph\GraphEditor).
 */
final class RepertoireManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RepertoireRepository $repertoires,
        private readonly Limits $limits,
        private readonly ClockInterface $clock,
        private readonly Transaction $transaction,
    ) {
    }

    /**
     * A new repertoire with its initial position.
     *
     * @throws LimitReachedException
     * @throws \InvalidArgumentException invalid name
     */
    public function create(User $user, string $name, Color $color): Repertoire
    {
        $name = self::name($name);

        return $this->transaction->run(function () use ($user, $name, $color): Repertoire {
            // Serialises concurrent creations of one user around the count.
            $this->entityManager->refresh($user, LockMode::PESSIMISTIC_WRITE);
            if ($this->repertoires->countByUser($user) >= $this->limits->maxRepertoires) {
                throw new LimitReachedException('repertoires', $this->limits->maxRepertoires);
            }
            $now = $this->now();
            $repertoire = new Repertoire($user, $name, $color, $now);
            $this->entityManager->persist($repertoire);
            $this->entityManager->persist(new Position($repertoire, PositionKey::of(Rules::initial()->normalizedFen()), 0, $now));
            $this->entityManager->flush();

            return $repertoire;
        });
    }

    /**
     * @throws RepertoireNotFoundException
     * @throws \InvalidArgumentException invalid name
     */
    public function rename(User $user, Uuid $id, string $name): Repertoire
    {
        $name = self::name($name);

        return $this->transaction->run(function () use ($user, $id, $name): Repertoire {
            $repertoire = $this->repertoires->lockOwned($id, $user) ?? throw new RepertoireNotFoundException();
            $repertoire->rename($name, $this->now());
            $this->entityManager->flush();

            return $repertoire;
        });
    }

    /**
     * Deletes the repertoire and everything in it (positions, moves, segments, journal).
     *
     * @throws RepertoireNotFoundException
     */
    public function delete(User $user, Uuid $id): void
    {
        $this->transaction->run(function () use ($user, $id): void {
            $repertoire = $this->repertoires->lockOwned($id, $user) ?? throw new RepertoireNotFoundException();
            // Foreign keys cascade; the entity manager forgets the rows it may hold.
            $this->entityManager->getConnection()->executeStatement('DELETE FROM repertoire WHERE id = ?', [$repertoire->getId()->toBinary()], [ParameterType::BINARY]);
            $this->entityManager->detach($repertoire);
        });
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function name(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name));
        if ('' === $name || mb_strlen($name) > Repertoire::NAME_MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf('A name has 1 to %d characters.', Repertoire::NAME_MAX_LENGTH));
        }

        return $name;
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
    }
}
