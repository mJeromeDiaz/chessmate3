<?php

declare(strict_types=1);

namespace App\Notification\Push;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Which push endpoints the server agrees to call (docs/SECURITY.md): HTTPS on the default port, on
 * the browsers' push services only. The server POSTs to a subscription's endpoint: without this
 * list, a forged subscription would make it call any URL (SSRF).
 */
final readonly class EndpointPolicy
{
    public const MAX_LENGTH = 2048;

    /**
     * @param list<string> $allowedHosts host names, or suffixes starting with a dot
     */
    public function __construct(
        #[Autowire('%notification.push.allowed_hosts%')]
        private array $allowedHosts,
    ) {
    }

    public function allows(string $endpoint): bool
    {
        if (\strlen($endpoint) > self::MAX_LENGTH || 1 !== preg_match('~^https://[^\s/?#@]+/\S*$~', $endpoint)) {
            return false;
        }
        $parts = parse_url($endpoint);
        if (false === $parts || 'https' !== ($parts['scheme'] ?? null) || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && 443 !== $parts['port'])) {
            return false;
        }
        $host = strtolower($parts['host'] ?? '');
        foreach ($this->allowedHosts as $allowed) {
            if (str_starts_with($allowed, '.') ? str_ends_with($host, $allowed) : $host === $allowed) {
                return true;
            }
        }

        return false;
    }
}
