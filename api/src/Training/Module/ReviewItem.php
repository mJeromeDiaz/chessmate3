<?php

declare(strict_types=1);

namespace App\Training\Module;

/**
 * One item of a run review: its outcome and module-specific data (plain scalars and arrays).
 */
final readonly class ReviewItem
{
    /** Solved without help. */
    public const OK = 'ok';
    /** Not solved, but no wrong move: a hint or the solution was asked. */
    public const HINT = 'hint';
    /** A wrong move. */
    public const FAIL = 'fail';

    /**
     * @param self::OK|self::HINT|self::FAIL $status
     * @param array<string, mixed>           $data
     */
    public function __construct(
        public string $type,
        public string $status,
        public ?int $durationMs,
        public array $data,
    ) {
    }

    /**
     * The status of a resolved puzzle attempt.
     *
     * @return self::OK|self::HINT|self::FAIL
     */
    public static function puzzleStatus(bool $solved, int $mistakes, int $hintLevel, bool $solutionShown): string
    {
        if ($solved) {
            return self::OK;
        }

        return 0 === $mistakes && ($hintLevel > 0 || $solutionShown) ? self::HINT : self::FAIL;
    }
}
