<?php

declare(strict_types=1);

namespace App\Security\UserAgent;

/**
 * A rough, best-effort "browser on OS" label from a User-Agent string, for the 2FA email
 * ("une info approximative sur l'appareil ou le navigateur") and the trusted-device list. Not a
 * real parser — full UA parsing is a losing battle against an ever-changing string nobody
 * standardized, and a rough label is all either use case needs.
 */
final class UserAgentSummarizer
{
    public function summarize(?string $userAgent): string
    {
        if (null === $userAgent || '' === trim($userAgent)) {
            return 'Unknown device';
        }

        return sprintf('%s on %s', $this->browser($userAgent), $this->os($userAgent));
    }

    private function browser(string $userAgent): string
    {
        return match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') || str_contains($userAgent, 'Opera') => 'Opera',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'CriOS/') || str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'a browser',
        };
    }

    private function os(string $userAgent): string
    {
        return match (true) {
            // Order matters: iOS UAs also contain "like Mac OS X", Android UAs also contain "Linux".
            str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS X') || str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => 'an unknown OS',
        };
    }
}
