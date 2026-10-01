<?php

declare(strict_types=1);

namespace App\ApiResource\Repertoire;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Restoring a suite of the trash: for each conflicting position (normalized FEN), which prepared
 * move stays, the suite's ("restored") or the repertoire's ("current"). Positions left out keep
 * their default (docs/REPERTOIRE.md, "Corbeille").
 */
final class RestoreInput extends ChangeInput
{
    /** @var array<string, 'restored'|'current'> */
    #[Assert\All([new Assert\Choice(choices: ['restored', 'current'])])]
    #[Assert\Count(max: 5000)]
    public array $choices = [];
}
