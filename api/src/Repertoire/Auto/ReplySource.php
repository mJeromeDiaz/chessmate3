<?php

declare(strict_types=1);

namespace App\Repertoire\Auto;

use App\Entity\User;
use App\Repertoire\Lichess\LichessUnavailableException;
use App\Repertoire\Limits;

/**
 * Where an exploration reads the explorer's replies of a position ({@see OpeningStatsReader}: the
 * precomputed table, else Lichess through its cache).
 *
 * @phpstan-import-type Replies from ReplyRule
 */
interface ReplySource
{
    /**
     * @param bool|null $fetched set to true when Lichess was really asked
     *
     * @param-out bool $fetched
     *
     * @return Replies
     *
     * @throws LichessUnavailableException
     */
    public function replies(?User $user, string $fen, ?bool &$fetched = null, string $preset = Limits::AUTO_EXPLORER_PRESET): array;
}
