<?php

declare(strict_types=1);

namespace App\ApiResource\Evaluation;

use App\Enum\Evaluation\Plan;
use App\Enum\Evaluation\PositionTag;
use App\Evaluation\EvaluationRules;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A position as an admin enters it (docs/EVALUATION.md). The FEN's legality is checked by
 * {@see \App\Evaluation\Position\PositionEditor}.
 */
final class PositionInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public string $fen = '';

    /** Centipawns, White's point of view; ±10 000 for a won position (mate, won endgame). */
    #[Assert\Range(min: -EvaluationRules::WON_CP, max: EvaluationRules::WON_CP)]
    public int $evalCp = 0;

    /** @var list<string> the key ideas shown with the correction (e.g. "Le fou c8 est bloqué par ses pions.") */
    #[Assert\Count(min: 1, max: EvaluationRules::IDEAS)]
    #[Assert\All([new Assert\Type('string'), new Assert\NotBlank(), new Assert\Length(max: 200)])]
    public array $ideas = [];

    /** Asked to the player only when set. */
    #[Assert\Choice(callback: [Plan::class, 'values'])]
    public ?string $plan = null;

    #[Assert\Length(max: 255)]
    public ?string $tip = null;

    #[Assert\Choice(callback: [PositionTag::class, 'values'])]
    public ?string $tag = null;

    #[Assert\Range(min: EvaluationRules::MIN_ELO, max: EvaluationRules::MAX_ELO)]
    public int $rating = EvaluationRules::DEFAULT_RATING;

    #[Assert\Length(max: 160)]
    public ?string $source = null;

    public bool $active = true;
}
