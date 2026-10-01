<?php

declare(strict_types=1);

namespace App\ApiResource\Repertoire;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Either a PGN text (pasted, or a file read by the client, with its name) or the URL of a Lichess
 * study or chapter. The size limit is checked in bytes by the server
 * (App\Repertoire\Limits::$maxImportBytes).
 */
final class CreateImportInput
{
    public ?string $pgn = null;

    #[Assert\Length(max: 255)]
    public ?string $fileName = null;

    #[Assert\Length(max: 255)]
    public ?string $studyUrl = null;

    #[Assert\Callback]
    public function validateSource(ExecutionContextInterface $context): void
    {
        $pgn = null !== $this->pgn && '' !== trim($this->pgn);
        $study = null !== $this->studyUrl && '' !== trim($this->studyUrl);
        if ($pgn === $study) {
            $context->buildViolation('Either a PGN text, or a Lichess study URL.')->atPath('pgn')->addViolation();
        }
    }
}
