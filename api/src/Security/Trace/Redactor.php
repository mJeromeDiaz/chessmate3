<?php

declare(strict_types=1);

namespace App\Security\Trace;

/**
 * Masks the secrets of a request (body or query string) before it reaches the trace log
 * (docs/SECURITY.md, § 2.12): passwords, one-time codes, tokens, keys, OAuth state, Web Push keys.
 *
 * Keys are compared once normalized (lowercase, letters and digits only), so `new_password`,
 * `newPassword` and `new-password` are the same key. A key is sensitive when it is in the list, or
 * when it contains one of the fragments: masking a harmless field is fine, leaking a secret is not.
 */
final class Redactor
{
    public const MASK = '[redacted]';

    /** Exact (normalized) keys: the request fields of src/Dto, the OAuth and signed-URL queries, Web Push. */
    private const KEYS = [
        'code',          // 2FA code, account deletion code, OAuth authorization code
        'key',           // early access key (/api/auth/invitation/check)
        'invitationkey',
        'state',         // OAuth state
        'signature',     // signed email verification link
        'auth',          // Web Push subscription keys
        'p256dh',
        'endpoint',      // Web Push endpoint: a capability URL
    ];

    /** Fragments: any key containing one of them (password, newPassword, pendingToken, clientSecret...). */
    private const FRAGMENTS = ['password', 'token', 'secret'];

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    public function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (\is_string($key) && $this->isSensitive($key)) {
                $data[$key] = self::MASK;
            } elseif (\is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }

    public function isSensitive(string $key): bool
    {
        $normalized = (string) preg_replace('/[^a-z0-9]/', '', strtolower($key));

        if (\in_array($normalized, self::KEYS, true)) {
            return true;
        }

        foreach (self::FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
