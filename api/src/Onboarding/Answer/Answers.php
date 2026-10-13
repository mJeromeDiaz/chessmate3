<?php

declare(strict_types=1);

namespace App\Onboarding\Answer;

use App\EarlyAccess\Stats\LichessRating;
use App\Entity\User;
use App\Enum\AuthProvider;
use App\Enum\Onboarding\Goal;
use App\Enum\Onboarding\Level;
use App\Puzzle\Rating\DeclaredRatingSeeder;
use App\Repository\Onboarding\AnswerRepository;
use App\Repository\Puzzle\RatingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The guided tour's answers (docs/ONBOARDING.md): the declared level, which seeds the puzzle rating
 * while no rated puzzle was played, and the goal, kept for later.
 *
 * @phpstan-type Lichess array{linked: bool, perf: string|null, rating: int|null}
 * @phpstan-type View array{level: Level|null, goal: Goal|null, suggestedLevel: Level|null, lichess: Lichess, canSeedRating: bool, puzzleRating: int|null}
 */
final readonly class Answers
{
    public function __construct(
        private AnswerRepository $answers,
        private RatingRepository $ratings,
        private DeclaredRatingSeeder $seeder,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return View
     */
    public function view(User $user): array
    {
        $answers = $this->answers->of($user);
        $lichess = $this->lichess($user);
        $rating = $this->ratings->findOneByUser($user);

        return [
            'level' => $answers['level'],
            'goal' => $answers['goal'],
            'suggestedLevel' => null === $lichess['rating'] ? null : Level::ofLichessRating($lichess['rating']),
            'lichess' => $lichess,
            'canSeedRating' => DeclaredRatingSeeder::canSeed($rating),
            'puzzleRating' => null === $rating ? null : (int) round($rating->getRating()),
        ];
    }

    /**
     * Replaces the answers; a level seeds the puzzle rating when it still can.
     */
    public function save(User $user, ?Level $level, ?Goal $goal): void
    {
        $now = $this->clock->now();
        // No refusal inside: the seeder only says whether it seeded.
        $this->entityManager->wrapInTransaction(function () use ($user, $level, $goal, $now): void {
            $this->answers->save($user, $level, $goal, $now);
            if (null !== $level) {
                $this->seeder->seed($user, $level->seedRating(), $now);
            }
        });
    }

    /**
     * The linked Lichess account and its rating, from the snapshot kept at the last Lichess sign-in
     * or link (no call to Lichess).
     *
     * @return Lichess
     */
    private function lichess(User $user): array
    {
        foreach ($user->getAuthIdentities() as $identity) {
            if (AuthProvider::Lichess === $identity->getProvider()) {
                $picked = LichessRating::pick($identity->getMetadata());

                return ['linked' => true, 'perf' => $picked['perf'] ?? null, 'rating' => $picked['rating'] ?? null];
            }
        }

        return ['linked' => false, 'perf' => null, 'rating' => null];
    }
}
