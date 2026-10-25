<?php

declare(strict_types=1);

namespace App\ApiResource\Repertoire;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /repertoires/{id}/lines: a line drafted in the editor, the moves (UCI) played from a
 * position of the repertoire. $replace: another prepared move on the way goes to the trash
 * (otherwise 409 position_occupied).
 */
final class SaveLineInput extends ChangeInput
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    public string $fromPositionId = '';

    /** @var list<string> */
    #[Assert\Count(min: 1, max: 200)]
    #[Assert\All([new Assert\Type('string'), new Assert\Regex('/^[a-h][1-8][a-h][1-8][qrbn]?$/')])]
    public array $ucis = [];

    public bool $replace = false;
}
