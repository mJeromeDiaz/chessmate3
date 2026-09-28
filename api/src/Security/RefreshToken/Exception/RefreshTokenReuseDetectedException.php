<?php

declare(strict_types=1);

namespace App\Security\RefreshToken\Exception;

/**
 * A refresh token that had already been rotated out (or raced against a concurrent rotation) was
 * presented again. The whole family has been revoked as a precaution by the time this is thrown.
 */
final class RefreshTokenReuseDetectedException extends RefreshTokenException
{
}
