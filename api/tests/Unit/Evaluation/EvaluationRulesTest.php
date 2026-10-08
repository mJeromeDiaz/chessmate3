<?php

declare(strict_types=1);

namespace App\Tests\Unit\Evaluation;

use App\Enum\Activity\ExerciseType;
use App\Enum\Evaluation\AttemptStatus;
use App\Enum\Training\Module;
use App\Evaluation\EvaluationRules;
use App\Gamification\Xp\XpRules;
use PHPUnit\Framework\TestCase;

/**
 * The rules of the position evaluation (docs/EVALUATION.md): categories, verdicts, labels, XP.
 */
final class EvaluationRulesTest extends TestCase
{
    public function testCategoriesFollowTheValidatedThresholds(): void
    {
        self::assertSame(
            [0, 0, 1, 1, 2, 2, -1, -2, 0],
            array_map(EvaluationRules::category(...), [0, 69, 70, 199, 200, 10_000, -70, -200, -69]),
        );
        // The design's examples: +1,4 is "White is better", +2,6 "White wins", −0,3 equal.
        self::assertSame([1, 2, 0], [EvaluationRules::category(140), EvaluationRules::category(260), EvaluationRules::category(-30)]);
    }

    public function testVerdicts(): void
    {
        self::assertSame(AttemptStatus::Exact, EvaluationRules::status(1, 1));
        self::assertSame(AttemptStatus::Close, EvaluationRules::status(2, 1));
        self::assertSame(AttemptStatus::Close, EvaluationRules::status(0, -1));
        self::assertSame(AttemptStatus::Miss, EvaluationRules::status(-1, 1));
        self::assertSame(AttemptStatus::Timeout, EvaluationRules::status(null, 0));
        self::assertTrue(EvaluationRules::fast(AttemptStatus::Exact, 29_999, 60));
        self::assertFalse(EvaluationRules::fast(AttemptStatus::Exact, 30_000, 60));
        self::assertFalse(EvaluationRules::fast(AttemptStatus::Close, 1_000, 60));
    }

    public function testLabels(): void
    {
        self::assertSame(['+1,4', '−0,3', '0,0', '+−', '−+'], array_map(EvaluationRules::label(...), [140, -30, 0, 10_000, -12_000]));
    }

    public function testXp(): void
    {
        $xp = static fn (array $metadata): int => XpRules::exercise(ExerciseType::PositionEvaluation, 'exact' === $metadata['status'], 30_000, 1, $metadata);
        self::assertSame(15, $xp(['status' => 'exact', 'planOk' => null, 'fast' => false]));
        self::assertSame(23, $xp(['status' => 'exact', 'planOk' => true, 'fast' => true]));
        self::assertSame(11, $xp(['status' => 'close', 'planOk' => true, 'fast' => false]));
        self::assertSame(1, $xp(['status' => 'miss', 'planOk' => false, 'fast' => false]));
        self::assertSame(1, $xp(['status' => 'timeout']));
        self::assertSame(Module::Evaluation, XpRules::module(ExerciseType::PositionEvaluation));
    }
}
