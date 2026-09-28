<?php

declare(strict_types=1);

namespace App\Security\RateLimit;

use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Consumes one hit from a rate limiter and turns a refusal into a 429 with a generic message —
 * never leaks which of the IP or identifier limiter tripped, so it can't be used to probe for
 * whether an identifier exists.
 */
final class RateLimitGuard
{
    public function consume(RateLimiterFactory $limiter, string $key): void
    {
        if (!$limiter->create($key)->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'Too many requests. Please try again later.');
        }
    }
}
