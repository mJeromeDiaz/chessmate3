<?php

declare(strict_types=1);

namespace App\ApiResource\Repertoire;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /repertoires/{id}/pending/{pendingId}/answer: the user's move in the pending position (UCI).
 */
final class AnswerPendingInput extends ChangeInput
{
    #[Assert\Regex('/^[a-h][1-8][a-h][1-8][qrbn]?$/')]
    public string $uci = '';
}
