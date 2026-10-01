<?php

declare(strict_types=1);

namespace App\Repertoire\Graph;

use App\Chess\Pgn\Nag;
use App\Entity\Repertoire\Move;
use App\Repertoire\Exception\InvalidAnnotationException;

/**
 * Validation of a move's annotations. The comment is plain text (displayed as text, never as
 * HTML): control characters other than newlines and tabs are removed, line endings unified, at
 * most {@see Move::COMMENT_MAX_LENGTH} characters; empty means none. At most
 * {@see Move::MAX_NAGS} distinct NAGs from 1 to 255, and at most one move assessment (1 to 6:
 * a move is not both "!" and "?").
 */
final class Annotation
{
    /**
     * @param list<int> $nags
     *
     * @return array{string|null, list<int>}
     *
     * @throws InvalidAnnotationException
     */
    public static function normalize(?string $comment, array $nags): array
    {
        if (null !== $comment) {
            if (!mb_check_encoding($comment, 'UTF-8')) {
                throw new InvalidAnnotationException('The comment is not valid UTF-8.');
            }
            $comment = str_replace(["\r\n", "\r"], "\n", $comment);
            $comment = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $comment));
            if (mb_strlen($comment) > Move::COMMENT_MAX_LENGTH) {
                throw new InvalidAnnotationException(sprintf('A comment has %d characters at most.', Move::COMMENT_MAX_LENGTH));
            }
            $comment = '' === $comment ? null : $comment;
        }

        $nags = array_values(array_unique($nags));
        if (\count($nags) > Move::MAX_NAGS) {
            throw new InvalidAnnotationException(sprintf('%d annotations at most.', Move::MAX_NAGS));
        }
        foreach ($nags as $nag) {
            if ($nag < 1 || $nag > Nag::MAX) {
                throw new InvalidAnnotationException('Unknown annotation.');
            }
        }
        if (\count(array_filter($nags, static fn (int $nag): bool => $nag <= Nag::DUBIOUS)) > 1) {
            throw new InvalidAnnotationException('One move assessment at most.');
        }
        sort($nags);

        return [$comment, $nags];
    }
}
