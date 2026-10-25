<?php

declare(strict_types=1);

namespace App\Calculation\Judge;

/**
 * A mate search that looked at more positions than allowed ({@see MateSearch::BUDGET}): the move is
 * not proven (judged wrong, chosen 2026-10-25).
 */
final class SearchBudgetExceededException extends \RuntimeException
{
}
