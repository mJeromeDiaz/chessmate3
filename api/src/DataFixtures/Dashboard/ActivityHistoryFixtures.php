<?php

declare(strict_types=1);

namespace App\DataFixtures\Dashboard;

use App\Activity\Event\ExerciseCompleted;
use App\DataFixtures\DemoUserFixtures;
use App\Entity\Activity\LogEntry;
use App\Entity\Puzzle\Rating;
use App\Entity\Puzzle\RatingChange;
use App\Entity\User;
use App\Enum\Activity\ExerciseType;
use App\Enum\Puzzle\RatingChangeReason;
use App\Puzzle\Rating\RatingState;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Twelve weeks of past activity for the demo user, so the dashboard is filled (docs/DASHBOARD.md):
 * activity log entries (source "demo") and a puzzle rating that climbs from 1500 with a few dips.
 * Deterministic: the same days and results on every load, relative to today.
 */
final class ActivityHistoryFixtures extends Fixture implements DependentFixtureInterface
{
    public const DAYS = 84;
    public const SOURCE_TYPE = 'demo';

    public function load(ObjectManager $manager): void
    {
        $user = $this->getReference(DemoUserFixtures::REFERENCE, User::class);
        $timezone = $user->getDateTimeZone();
        $utc = new \DateTimeZone('UTC');
        $today = new \DateTimeImmutable('today', $timezone);
        $rating = new Rating($user);
        $state = $rating->getState();
        $sequence = 0;

        for ($back = self::DAYS - 1; $back >= 1; --$back) {
            // Rest about two days a week, play every day of the last ten.
            if ($back > 10 && ($back * 13 + 5) % 7 < 2) {
                continue;
            }
            $day = $today->modify(\sprintf('-%d days', $back))->setTime(18, 30);
            $puzzles = 4 + ($back * 7) % 9;
            for ($i = 0; $i < $puzzles; ++$i) {
                $at = $day->modify(\sprintf('+%d minutes', 2 * $i))->setTimezone($utc);
                $success = ($back + $i * 3) % 5 !== 0;
                $this->log($manager, $user, ExerciseType::PuzzleRated, $success, 40_000 + 3_000 * ($i % 7), $at, ++$sequence);
                $after = new RatingState(
                    $state->rating + ($success ? 2.5 : -6.0) - ($back % 11 === 0 ? 4 : 0),
                    max(60.0, $state->deviation * 0.97),
                    $state->volatility,
                );
                $manager->persist(new RatingChange($user, RatingChangeReason::Attempt, $state, $after, $at));
                $rating->recordAttempt($after, $at);
                $state = $after;
            }
            if (0 === $back % 3) {
                for ($i = 0; $i < 10; ++$i) {
                    $this->log($manager, $user, ExerciseType::WoodpeckerPuzzle, 0 !== $i % 4, 25_000, $day->modify(\sprintf('+%d minutes', 40 + $i))->setTimezone($utc), ++$sequence);
                }
            }
            if (0 === $back % 2) {
                for ($i = 0; $i < 3; ++$i) {
                    $this->log($manager, $user, ExerciseType::RepertoireSegment, 2 !== $i, 60_000, $day->modify(\sprintf('-%d minutes', 30 - 5 * $i))->setTimezone($utc), ++$sequence);
                }
            }
        }
        $manager->persist($rating);
        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [DemoUserFixtures::class];
    }

    private function log(ObjectManager $manager, User $user, ExerciseType $type, bool $success, int $durationMs, \DateTimeImmutable $at, int $sequence): void
    {
        $manager->persist(new LogEntry($user, new ExerciseCompleted(
            $user->getId()->toRfc4122(), $type, $success, $durationMs, 1, self::SOURCE_TYPE, 'demo-'.$sequence, $at,
        )));
    }
}
