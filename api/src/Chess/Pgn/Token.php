<?php

declare(strict_types=1);

namespace App\Chess\Pgn;

/**
 * A lexical token of a PGN text ({@see Tokenizer}).
 */
final readonly class Token
{
    public const TAG = 'tag';
    public const COMMENT = 'comment';
    public const SAN = 'san';
    public const NAG = 'nag';
    public const OPEN = 'open';
    public const CLOSE = 'close';
    public const RESULT = 'result';

    /**
     * @param self::* $type
     * @param string  $value tag value, comment text, SAN, NAG number, result
     * @param string  $name  tag name (tags only)
     */
    public function __construct(
        public string $type,
        public string $value,
        public int $line,
        public string $name = '',
    ) {
    }
}
