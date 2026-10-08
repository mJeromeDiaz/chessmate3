<?php

declare(strict_types=1);

namespace App\ApiResource\Evaluation;

use App\Evaluation\EvaluationRules;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A FEN and the evaluation an admin is about to save.
 */
final class PositionCheckInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public string $fen = '';

    #[Assert\Range(min: -EvaluationRules::WON_CP, max: EvaluationRules::WON_CP)]
    public int $evalCp = 0;
}
