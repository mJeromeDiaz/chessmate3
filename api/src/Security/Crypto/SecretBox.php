<?php

declare(strict_types=1);

namespace App\Security\Crypto;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Authenticated symmetric encryption (libsodium secretbox: XSalsa20-Poly1305) for secrets we must
 * be able to read back, such as a Lichess access token kept for future game imports.
 *
 * Output: "v1:" + base64(nonce ‖ ciphertext) (the prefix is the format version). Any tampering,
 * or a key that isn't ours, makes {@see self::decrypt()} throw.
 *
 * Key rotation (docs/SECURITY.md): the new key becomes OAUTH_TOKEN_ENCRYPTION_KEY and the old one
 * OAUTH_TOKEN_ENCRYPTION_KEY_PREVIOUS. Values under the old key still decrypt (the MAC tells which
 * key fits), {@see self::needsReencryption()} flags them, and they are re-encrypted with the new key
 * as they are rewritten (`app:oauth-tokens:reencrypt` does all of them at once).
 */
final readonly class SecretBox
{
    private const PREFIX = 'v1:';

    private string $key;

    private ?string $previousKey;

    public function __construct(
        #[Autowire('%env(OAUTH_TOKEN_ENCRYPTION_KEY)%')]
        #[\SensitiveParameter]
        string $base64Key,
        #[Autowire('%env(default::OAUTH_TOKEN_ENCRYPTION_KEY_PREVIOUS)%')]
        #[\SensitiveParameter]
        ?string $base64PreviousKey = null,
        /** The key's variable, named in errors (another instance encrypts calendar tokens). */
        string $keyName = 'OAUTH_TOKEN_ENCRYPTION_KEY',
    ) {
        $this->key = self::decodeKey($base64Key, $keyName);
        $this->previousKey = null !== $base64PreviousKey && '' !== $base64PreviousKey
            ? self::decodeKey($base64PreviousKey, $keyName.'_PREVIOUS')
            : null;
    }

    public function encrypt(#[\SensitiveParameter] string $plaintext): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX.base64_encode($nonce.sodium_crypto_secretbox($plaintext, $nonce, $this->key));
    }

    /**
     * @throws \UnexpectedValueException if the value is malformed, tampered with or from another key
     */
    public function decrypt(string $encrypted): string
    {
        return $this->open($encrypted)[0];
    }

    /**
     * Whether the value was encrypted with the previous key (and should be rewritten).
     *
     * @throws \UnexpectedValueException like {@see self::decrypt()}
     */
    public function needsReencryption(string $encrypted): bool
    {
        return $this->open($encrypted)[1];
    }

    /**
     * @return array{string, bool} the plaintext, and whether the previous key was needed
     */
    private function open(string $encrypted): array
    {
        $raw = str_starts_with($encrypted, self::PREFIX) ? base64_decode(substr($encrypted, \strlen(self::PREFIX)), true) : false;

        if (false === $raw || \strlen($raw) < \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + \SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new \UnexpectedValueException('Malformed encrypted value.');
        }

        $nonce = substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);
        if (false !== $plaintext) {
            return [$plaintext, false];
        }

        $plaintext = null !== $this->previousKey ? sodium_crypto_secretbox_open($ciphertext, $nonce, $this->previousKey) : false;
        if (false !== $plaintext) {
            return [$plaintext, true];
        }

        throw new \UnexpectedValueException('Encrypted value failed authentication.');
    }

    private static function decodeKey(#[\SensitiveParameter] string $base64Key, string $name): string
    {
        $key = base64_decode($base64Key, true);

        if (false === $key || \SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($key)) {
            throw new \InvalidArgumentException($name.' must be 32 random bytes, base64-encoded (openssl rand -base64 32).');
        }

        return $key;
    }
}
