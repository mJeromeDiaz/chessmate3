<?php

declare(strict_types=1);

namespace App\ApiResource\Onboarding;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use App\State\Onboarding\OnboardingProcessor;
use App\State\Onboarding\OnboardingProvider;

/**
 * The signed-in user's guided tour answers (docs/ONBOARDING.md).
 *
 * @phpstan-import-type View from \App\Onboarding\Answer\Answers
 */
#[ApiResource(
    shortName: 'Onboarding',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(uriTemplate: '/onboarding', provider: OnboardingProvider::class),
        new Put(uriTemplate: '/onboarding', input: OnboardingInput::class, read: false, processor: OnboardingProcessor::class),
    ],
)]
final class Onboarding
{
    /** beginner, amateur, confirmed, expert, pro; null: not declared. */
    public ?string $level = null;
    /** rating, blunders, fun, understanding, kasparov; null: none picked. */
    public ?string $goal = null;
    /** The level of the linked Lichess account's rating (rapid, else blitz, else classical). */
    public ?string $suggestedLevel = null;
    /** @var array{linked: bool, perf: string|null, rating: int|null} */
    public array $lichess = ['linked' => false, 'perf' => null, 'rating' => null];
    /** Whether a declared level still sets the puzzle rating (no rated puzzle, no Lichess import). */
    public bool $canSeedRating = true;
    /** The current puzzle rating; null: none yet (1500 on the first puzzle). */
    public ?int $puzzleRating = null;

    /**
     * @param View $view
     */
    public static function of(array $view): self
    {
        $resource = new self();
        $resource->level = $view['level']?->value;
        $resource->goal = $view['goal']?->value;
        $resource->suggestedLevel = $view['suggestedLevel']?->value;
        $resource->lichess = $view['lichess'];
        $resource->canSeedRating = $view['canSeedRating'];
        $resource->puzzleRating = $view['puzzleRating'];

        return $resource;
    }
}
