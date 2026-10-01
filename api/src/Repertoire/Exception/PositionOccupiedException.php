<?php

declare(strict_types=1);

namespace App\Repertoire\Exception;

/**
 * The user already has a prepared move in this position (one per position): replace it instead
 * (App\Repertoire\Graph\GraphEditor::replace()).
 */
final class PositionOccupiedException extends \DomainException
{
    public function __construct(public readonly string $preparedMoveId)
    {
        parent::__construct('A move is already prepared in this position: replace it.');
    }
}
