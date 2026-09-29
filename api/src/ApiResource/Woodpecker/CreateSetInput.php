<?php

declare(strict_types=1);

namespace App\ApiResource\Woodpecker;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Defaults: docs/WOODPECKER.md, "Creating a set". Without ratingMin/ratingMax, the range is
 * derived from the user's puzzle rating (easy puzzles, as the method recommends). The lower
 * bound of puzzleCount comes from the woodpecker.min_puzzles parameter (checked by the processor).
 */
#[Assert\Expression(
    'this.ratingMin === null and this.ratingMax === null or (this.ratingMin !== null and this.ratingMax !== null and this.ratingMax - this.ratingMin >= 100)',
    message: 'Give both rating bounds, at least 100 apart, or none.',
)]
final class CreateSetInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    public string $name = '';

    #[Assert\Range(min: 1, max: 1500)]
    public int $puzzleCount = 300;

    #[Assert\Range(min: 400, max: 3200)]
    public ?int $ratingMin = null;

    #[Assert\Range(min: 400, max: 3200)]
    public ?int $ratingMax = null;

    /** @var list<string> */
    #[Assert\Count(max: 10)]
    #[Assert\All([new Assert\Type('string'), new Assert\Length(max: 32)])]
    public array $themes = [];

    #[Assert\Range(min: 2, max: 10)]
    public int $cycleCount = 7;

    #[Assert\Range(min: 1, max: 90)]
    public int $firstCycleDays = 28;

    #[Assert\Range(min: 0.3, max: 1)]
    public float $reductionFactor = 0.5;

    #[Assert\Range(min: 1, max: 90)]
    public int $minCycleDays = 1;

    #[Assert\Range(min: 0, max: 14)]
    public int $restDays = 0;

    public bool $shuffle = false;
}
