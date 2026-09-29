<?php

declare(strict_types=1);

namespace App\Tests\Functional\Woodpecker;

use App\Entity\Puzzle\Puzzle;
use App\Entity\User;
use App\Woodpecker\Integration\ActiveSetExclusion;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Uid\Uuid;

/**
 * Set modes at the database level (migration Version20260929074928): classic by default, the
 * schedule tied to the mode, one ongoing set per mode, every ongoing set excluded from the rated
 * selection. Light sets are inserted in SQL until their creation exists.
 */
final class SetModeTest extends WoodpeckerWebTestCase
{
    public function testNewSetsAreClassic(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $set = $this->createSet($alice);

        self::assertSame('classic', $this->json($this->api('GET', '/api/woodpecker/sets/'.$set['id'], $alice))['mode']);
    }

    public function testTheDatabaseTiesTheScheduleToTheMode(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $set = $this->createSet($alice);

        foreach ([
            "UPDATE woodpecker_set SET mode = 'light' WHERE id = :id",
            'UPDATE woodpecker_set SET rest_days = NULL WHERE id = :id',
        ] as $sql) {
            try {
                $this->entityManager->getConnection()->executeStatement($sql, ['id' => Uuid::fromString($set['id'])->toBinary()]);
                self::fail('The CHECK constraint should reject: '.$sql);
            } catch (DriverException $e) {
                self::assertStringContainsString('chk_woodpecker_set_mode_config', $e->getMessage());
            }
        }

        $this->expectException(DriverException::class);
        $this->insertLightSet($alice, cycleCount: 7);
    }

    public function testOneOngoingSetPerMode(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->createSet($alice);
        $light = $this->insertLightSet($alice);

        try {
            $this->insertLightSet($alice);
            self::fail('A second ongoing light set should be rejected.');
        } catch (UniqueConstraintViolationException) {
        }

        $this->entityManager->getConnection()->executeStatement(
            "UPDATE woodpecker_set SET status = 'abandoned' WHERE id = :id",
            ['id' => $light],
        );
        $this->insertLightSet($alice);
        $this->addToAssertionCount(1);
    }

    public function testThePuzzlesOfEveryOngoingSetLeaveTheRatedSelection(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $classic = $this->createSet($alice);
        $connection = $this->entityManager->getConnection();
        $classicPuzzles = array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $connection->fetchFirstColumn(
            'SELECT puzzle_id FROM woodpecker_set_puzzle WHERE set_id = :id',
            ['id' => Uuid::fromString($classic['id'])->toBinary()],
        ));
        $candidates = array_map(static fn (Puzzle $puzzle): int => (int) $puzzle->getId(), $this->selectablePuzzles());
        $lightPuzzle = array_values(array_diff($candidates, $classicPuzzles))[0];
        $connection->executeStatement(
            'INSERT INTO woodpecker_set_puzzle (set_id, position, puzzle_id) VALUES (:set, 0, :puzzle)',
            ['set' => $this->insertLightSet($alice), 'puzzle' => $lightPuzzle],
        );

        $excluded = self::getContainer()->get(ActiveSetExclusion::class)->excludedAmong($alice, $candidates);
        sort($excluded);
        $expected = [...$classicPuzzles, $lightPuzzle];
        sort($expected);

        self::assertSame($expected, $excluded);
    }

    /**
     * @return string the set id (binary)
     */
    private function insertLightSet(User $user, ?int $cycleCount = null): string
    {
        $id = Uuid::v7()->toBinary();
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO woodpecker_set (id, user_id, name, mode, status, puzzle_count, rating_min, rating_max, themes, cycle_count, shuffle, created_at)
             VALUES (:id, :user, 'Light', 'light', 'active', 1, 400, 3200, '[]', :cycles, 0, UTC_TIMESTAMP())",
            ['id' => $id, 'user' => $user->getId()->toBinary(), 'cycles' => $cycleCount],
        );

        return $id;
    }
}
