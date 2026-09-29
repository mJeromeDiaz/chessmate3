<?php

declare(strict_types=1);

namespace App\DataFixtures\Woodpecker;

use App\DataFixtures\DemoUserFixtures;
use App\DataFixtures\Puzzle\PuzzleFixtures;
use App\Entity\User;
use App\Entity\Woodpecker\Set;
use App\Enum\Training\Module;
use App\Training\Module\ItemSubmission;
use App\Training\Run\TimeboxRunner;
use App\Woodpecker\Set\LightConfig;
use App\Woodpecker\Set\SetConfig;
use App\Woodpecker\Set\SetManager;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * Demo training data of the demo user: a classic set and a light set, each with a few past timed
 * runs, played through the real services (so cycles, growths and events are consistent) on a
 * clock moved back a few days.
 *
 * The sample pool holds ~50 puzzles: the light set starts at 20 (not the configured initial size)
 * and its second run grows it once.
 */
final class WoodpeckerFixtures extends Fixture implements DependentFixtureInterface
{
    private const RATING_MIN = 400;
    private const RATING_MAX = 3200;
    /** Time spent on each puzzle of a demo run. */
    private const SECONDS_PER_ITEM = 40;

    private MockClock $clock;

    public function __construct(
        private readonly SetManager $sets,
        private readonly TimeboxRunner $runner,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $user = $this->getReference(DemoUserFixtures::REFERENCE, User::class);
        $this->clock = new MockClock(new \DateTimeImmutable('-3 days 18:00', new \DateTimeZone('UTC')));
        Clock::set($this->clock);

        try {
            $classic = $this->sets->create($user, 'Tactiques de base', new SetConfig(
                puzzleCount: 20,
                ratingMin: self::RATING_MIN,
                ratingMax: self::RATING_MAX,
                themes: [],
                cycleCount: 5,
                firstCycleDays: 14,
                reductionFactor: 0.5,
                minCycleDays: 1,
                restDays: 1,
                shuffle: false,
            ));
            $light = $this->sets->create($user, 'Séances express', new LightConfig(
                puzzleCount: 20,
                ratingMin: self::RATING_MIN,
                ratingMax: self::RATING_MAX,
                themes: [],
                shuffle: true,
            ));

            // Day 1: a short light run, stopped early.
            $this->playRun($user, $light, 600, 8);
            // Day 2: a longer light run that goes past the set's end (it grows), and a classic run.
            $this->clock->modify('+1 day');
            $this->playRun($user, $light, 900, 19);
            $this->clock->modify('+2 hours');
            $this->playRun($user, $classic, 600, 6);
        } finally {
            Clock::set(new NativeClock());
        }
    }

    public function getDependencies(): array
    {
        return [DemoUserFixtures::class, PuzzleFixtures::class];
    }

    /**
     * Plays $count items of a new run (one in three failed with the solution shown), then stops it.
     */
    private function playRun(User $user, Set $set, int $budgetSeconds, int $count): void
    {
        $run = $this->runner->start($user, Module::Woodpecker, $set->getId(), $budgetSeconds);
        $runId = $run->getId();
        for ($i = 0; $i < $count; ++$i) {
            $item = $this->runner->next($user, $runId)->item;
            if (null === $item) {
                break;
            }
            $this->clock->sleep(self::SECONDS_PER_ITEM);
            $failed = 2 === $i % 3;
            $this->runner->submit($user, $runId, new ItemSubmission(
                itemId: $item->id,
                moves: $failed ? [] : self::playerMoves($item->data['puzzle'] ?? null),
                hintLevel: 0,
                solutionShown: $failed,
            ));
        }
        $this->clock->sleep(5);
        $this->runner->stop($user, $runId);
        $this->clock->modify('+1 hour');
    }

    /**
     * The player's moves of a served puzzle: every other move of its line, from the second one.
     *
     * @return list<string>
     */
    private static function playerMoves(mixed $puzzle): array
    {
        $moves = [];
        $line = \is_array($puzzle) && \is_array($puzzle['moves'] ?? null) ? array_values($puzzle['moves']) : [];
        foreach ($line as $index => $move) {
            if (1 === $index % 2 && \is_string($move)) {
                $moves[] = $move;
            }
        }

        return $moves;
    }
}
