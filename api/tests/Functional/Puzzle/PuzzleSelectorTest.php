<?php

declare(strict_types=1);

namespace App\Tests\Functional\Puzzle;

use App\Entity\Puzzle\Attempt;
use App\Enum\Puzzle\Difficulty;
use App\Puzzle\Selection\PuzzleSelector;
use App\Puzzle\Selection\SelectionCriteria;
use App\Repository\Puzzle\AttemptRepository;
use App\Repository\Puzzle\ThemeRepository;
use Doctrine\DBAL\Connection;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class PuzzleSelectorTest extends PuzzleWebTestCase
{
    private PuzzleSelector $selector;

    protected function setUp(): void
    {
        parent::setUp();
        $container = self::getContainer();
        $this->selector = new PuzzleSelector(
            $container->get(Connection::class),
            $container->get(AttemptRepository::class),
            new Randomizer(new Mt19937(42)),
        );
    }

    public function testTheWindowTargetsAbout65PercentAndWidensWithTheDeviation(): void
    {
        self::assertSame([1400, 250], $this->selector->window(1500, 350, new SelectionCriteria()));
        self::assertSame([1900, 100], $this->selector->window(2000, 50, new SelectionCriteria()));
        self::assertSame([1650, 100], $this->selector->window(2000, 50, new SelectionCriteria(difficulty: Difficulty::Easier)));
        self::assertSame([2150, 100], $this->selector->window(2000, 50, new SelectionCriteria(difficulty: Difficulty::Harder)));
    }

    public function testASelectedPuzzleIsInTheWindowWhenOneExists(): void
    {
        $user = $this->createUser('alice@example.com');
        $ratings = array_map(static fn ($p): int => $p->getRating(), $this->selectablePuzzles());
        sort($ratings);
        $median = $ratings[intdiv(\count($ratings), 2)];

        for ($i = 0; $i < 20; ++$i) {
            $id = $this->selector->select($user, $median + 100, 60, new SelectionCriteria());
            self::assertNotNull($id);
            $puzzle = $this->puzzleById($id);
            // Initial window: centre = median, half-width = 75 + 30.
            self::assertLessThanOrEqual(105, abs($puzzle->getRating() - $median), 'widened although the window had puzzles');
        }
    }

    public function testTheWindowWidensWhenNothingIsClose(): void
    {
        $user = $this->createUser('alice@example.com');

        // Far above every sample puzzle: only the widest windows reach them.
        self::assertNotNull($this->selector->select($user, 3300, 45, new SelectionCriteria()));
    }

    public function testThemeFilterIsAnOr(): void
    {
        $user = $this->createUser('alice@example.com');
        $themeIds = $this->themeIds(['mateIn1', 'promotion']);

        for ($i = 0; $i < 10; ++$i) {
            $id = $this->selector->select($user, 1500, 350, new SelectionCriteria($themeIds));
            self::assertNotNull($id);
            self::assertNotEmpty(array_intersect(['mateIn1', 'promotion'], $this->puzzleById($id)->getThemes()));
        }
    }

    public function testRatedPuzzlesAreExcludedAndNothingIsLeftAtTheEnd(): void
    {
        $user = $this->createUser('alice@example.com');
        $keep = $this->selectablePuzzles()[0];
        foreach ($this->puzzles as $puzzle) {
            if ($puzzle !== $keep) {
                $this->entityManager->persist(new Attempt($user, $puzzle, true, new \DateTimeImmutable()));
            }
        }
        $this->entityManager->flush();

        // Only one unplayed puzzle left: found wherever it is, through retries and widening.
        self::assertSame($keep->getId(), $this->selector->select($user, 1500, 350, new SelectionCriteria()));

        $this->entityManager->persist(new Attempt($user, $keep, true, new \DateTimeImmutable()));
        $this->entityManager->flush();
        self::assertNull($this->selector->select($user, 1500, 350, new SelectionCriteria()));
    }

    public function testAnUnratedAttemptDoesNotExcludeAPuzzleOfAnotherUser(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        foreach ($this->puzzles as $puzzle) {
            $this->entityManager->persist(new Attempt($alice, $puzzle, true, new \DateTimeImmutable()));
        }
        $this->entityManager->flush();

        self::assertNull($this->selector->select($alice, 1500, 350, new SelectionCriteria()));
        self::assertNotNull($this->selector->select($bob, 1500, 350, new SelectionCriteria()));
    }

    public function testUnselectablePuzzlesAreNeverServed(): void
    {
        $user = $this->createUser('alice@example.com');
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('UPDATE puzzle SET selectable = 0');
        $connection->executeStatement('DELETE FROM puzzle_theme_membership');

        self::assertNull($this->selector->select($user, 1500, 350, new SelectionCriteria()));
        self::assertNull($this->selector->select($user, 1500, 350, new SelectionCriteria($this->themeIds(['mateIn1']))));
    }

    /**
     * @param list<string> $keys
     *
     * @return list<int>
     */
    private function themeIds(array $keys): array
    {
        return array_map(
            static fn ($theme): int => (int) $theme->getId(),
            self::getContainer()->get(ThemeRepository::class)->findByKeys($keys),
        );
    }

    private function puzzleById(int $id): \App\Entity\Puzzle\Puzzle
    {
        foreach ($this->puzzles as $puzzle) {
            if ($puzzle->getId() === $id) {
                return $puzzle;
            }
        }

        self::fail("Unknown puzzle $id");
    }
}
