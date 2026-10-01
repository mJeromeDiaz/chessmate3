<?php

declare(strict_types=1);

namespace App\Chess\Position;

/**
 * A position identified by its normalized FEN ({@see FenNormalizer}) and a 16-byte digest of it
 * (first half of SHA-256), stored in BINARY(16) columns and indexed for fast lookups. The digest
 * alone is not trusted for equality: rows also keep the FEN (binary collation).
 */
final readonly class PositionKey
{
    private function __construct(
        public string $fen,
        public string $hash,
    ) {
    }

    public static function of(string $normalizedFen): self
    {
        return new self($normalizedFen, substr(hash('sha256', $normalizedFen, true), 0, 16));
    }

    public function hex(): string
    {
        return bin2hex($this->hash);
    }

    public function equals(self $other): bool
    {
        return $this->fen === $other->fen;
    }
}
