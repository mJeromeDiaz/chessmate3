<?php

declare(strict_types=1);

namespace App\ApiResource\Coach;

/**
 * POST /coach/builds/{id}/session: whether to generate the repertoire from these games when the
 * user has none.
 */
final class ComposeInput
{
    public bool $withRepertoire = false;
}
