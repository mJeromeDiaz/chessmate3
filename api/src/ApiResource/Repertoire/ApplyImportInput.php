<?php

declare(strict_types=1);

namespace App\ApiResource\Repertoire;

use App\Entity\Repertoire\Repertoire as RepertoireEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Where to import: an existing repertoire of the user (repertoireId, with the version the preview
 * was based on), or a new one (name, color). choices: normalized FEN => UCI of the move chosen as
 * reference for each conflict (the others keep their default).
 */
final class ApplyImportInput
{
    #[Assert\Uuid]
    public ?string $repertoireId = null;

    #[Assert\Length(max: RepertoireEntity::NAME_MAX_LENGTH)]
    public ?string $name = null;

    #[Assert\Choice(choices: ['white', 'black'])]
    public ?string $color = null;

    /** @var array<string, string> */
    #[Assert\All([new Assert\Type('string'), new Assert\Regex('/^[a-h][1-8][a-h][1-8][qrbn]?$/')])]
    #[Assert\Count(max: 5000)]
    public array $choices = [];

    #[Assert\PositiveOrZero]
    public ?int $baseVersion = null;

    #[Assert\Callback]
    public function validateTarget(ExecutionContextInterface $context): void
    {
        $existing = null !== $this->repertoireId;
        $new = null !== $this->name && '' !== trim($this->name) && null !== $this->color;
        if ($existing === $new) {
            $context->buildViolation('Either a repertoire (repertoireId), or a name and a color for a new one.')
                ->atPath('repertoireId')
                ->addViolation();
        }
    }
}
