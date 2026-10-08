<?php

declare(strict_types=1);

namespace App\ApiResource\Evaluation;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\State\Evaluation\PositionCheckProcessor;

/**
 * An admin's position checked against Lichess before it is saved (docs/EVALUATION.md): the
 * tablebase for 7 pieces or fewer, the cloud evaluation otherwise. `verdict`: ok, mismatch (another
 * category), drift (same category, more than 1 pawn apart), unverified (unknown to Lichess),
 * unavailable (Lichess did not answer).
 */
#[ApiResource(
    shortName: 'EvaluationPositionCheck',
    security: "is_granted('ROLE_ADMIN')",
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Post(
            uriTemplate: '/admin/evaluation/verification',
            status: 200,
            openapi: new Operation(summary: 'Checks a FEN and its evaluation against Lichess (nothing is saved).'),
            input: PositionCheckInput::class,
            processor: PositionCheckProcessor::class,
        ),
    ],
)]
final class PositionCheck
{
    /** The FEN normalized. */
    public string $fen = '';
    public string $turn = 'white';
    /** The category of the evaluation entered, -2 to 2. */
    public int $category = 0;
    public bool $nearBorder = false;
    public string $verdict = 'unverified';
    /** tablebase or cloud */
    public string $source = '';
    /** What Lichess says ("win", "+0,4 (depth 30)"…). */
    public ?string $lichess = null;
    public ?int $lichessCategory = null;
}
