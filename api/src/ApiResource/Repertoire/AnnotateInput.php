<?php

declare(strict_types=1);

namespace App\ApiResource\Repertoire;

use App\Entity\Repertoire\Move;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Replaces the move's comment (plain text, null or empty: none) and NAGs.
 */
final class AnnotateInput extends ChangeInput
{
    #[Assert\Length(max: Move::COMMENT_MAX_LENGTH)]
    public ?string $comment = null;

    /** @var list<int> */
    #[Assert\Count(max: Move::MAX_NAGS)]
    #[Assert\All([new Assert\Type('integer'), new Assert\Range(min: 1, max: 255)])]
    public array $nags = [];
}
