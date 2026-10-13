<?php

declare(strict_types=1);

namespace App\ApiResource\Repertoire;

/**
 * The user's selection on a build: family keys kept, moves chosen (family key => node path =>
 * UCI) and lines removed (family key => node paths). Checked against the build by
 * App\Repertoire\Auto\Selection::checked() (422 invalid_selection).
 */
final class AutoBuildSelectionInput
{
    /** @var list<string> */
    public array $families = [];

    /** @var array<string, array<string, string>> */
    public array $choices = [];

    /** @var array<string, list<string>> */
    public array $removed = [];
}
