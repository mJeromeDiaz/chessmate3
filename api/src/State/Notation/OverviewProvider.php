<?php

declare(strict_types=1);

namespace App\State\Notation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Notation\Overview;
use App\Notation\Drawing\LinePicker;
use App\Notation\NotationRules;
use App\Security\AuthenticatedUser;

/**
 * GET /notation: the rules of the line exercise, the player's repertoires and their segments.
 *
 * @implements ProviderInterface<Overview>
 */
final class OverviewProvider implements ProviderInterface
{
    public function __construct(
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly LinePicker $lines,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Overview
    {
        $overview = new Overview();
        $overview->rules = ['lineMinSegments' => NotationRules::LINE_MIN_SEGMENTS, 'lineWritePlies' => NotationRules::LINE_WRITE_PLIES];
        foreach ($this->lines->standings($this->authenticatedUser->get()) as $standing) {
            $repertoire = $standing['repertoire'];
            $overview->repertoires[] = [
                'id' => $repertoire->getId()->toRfc4122(),
                'name' => $repertoire->getName(),
                'color' => $repertoire->getColor()->value,
                'segments' => $standing['segments'],
                'eligible' => $standing['eligible'],
            ];
        }

        return $overview;
    }
}
