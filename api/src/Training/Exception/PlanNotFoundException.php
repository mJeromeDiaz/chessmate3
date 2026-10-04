<?php

declare(strict_types=1);

namespace App\Training\Exception;

/**
 * Unknown saved session, or another user's.
 */
final class PlanNotFoundException extends \RuntimeException
{
}
