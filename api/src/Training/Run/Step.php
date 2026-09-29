<?php

declare(strict_types=1);

namespace App\Training\Run;

use App\Entity\Training\Run;
use App\Training\Module\Item;
use App\Training\Module\ItemResult;

/**
 * The state of a run after a request: the item to play next (null once the run is closed) and the
 * verdict on a submission.
 */
final readonly class Step
{
    public function __construct(
        public Run $run,
        public ?Item $item = null,
        public ?ItemResult $result = null,
    ) {
    }
}
