<?php

declare(strict_types=1);

namespace App\ApiResource\Training;

use App\Entity\Puzzle\Attempt;
use App\Puzzle\Solution\SolutionValidator;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The client reports what happened on an item, never whether it succeeded.
 */
final class SubmitItemInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 64)]
    public string $itemId = '';

    /** @var list<string> UCI moves tried by the player, in order, wrong ones included */
    #[Assert\Count(max: SolutionValidator::MAX_LOGGED_MOVES)]
    #[Assert\All([new Assert\Type('string'), new Assert\Regex('/^[a-h][1-8][a-h][1-8][qrbn]?$/')])]
    public array $moves = [];

    #[Assert\Range(min: 0, max: Attempt::MAX_HINT_LEVEL)]
    public int $hintLevel = 0;

    public bool $solutionShown = false;

    /** Think time measured by the client, ms: lowers the server's own measure, never raises it. */
    #[Assert\Range(min: 0, max: 3_600_000)]
    public ?int $thinkMs = null;
}
