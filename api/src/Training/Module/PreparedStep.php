<?php

declare(strict_types=1);

namespace App\Training\Module;

use Symfony\Component\Uid\Uuid;

/**
 * What a session step becomes for its module: the run's subject and config.
 */
final readonly class PreparedStep
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public Uuid $subjectId,
        public array $config,
    ) {
    }
}
