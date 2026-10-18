<?php

declare(strict_types=1);

namespace App\ApiResource\Repertoire;

/**
 * The user's selection on a build: family keys kept, moves chosen (family key => node path =>
 * UCI), lines removed (family key => node paths), short lines kept (family key => line paths) and
 * set aside as pending (family key => line cuts). Checked against the build by
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

    /** @var array<string, list<string>> */
    public array $kept = [];

    /** @var array<string, list<string>> */
    public array $suspended = [];
}
