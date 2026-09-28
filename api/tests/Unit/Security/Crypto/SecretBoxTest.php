<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\Crypto;

use App\Security\Crypto\SecretBox;
use PHPUnit\Framework\TestCase;

final class SecretBoxTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $box = new SecretBox(self::key());

        self::assertSame('lio_secret-token', $box->decrypt($box->encrypt('lio_secret-token')));
    }

    public function testCiphertextIsVersionedAndNeverContainsThePlaintext(): void
    {
        $encrypted = (new SecretBox(self::key()))->encrypt('lio_secret-token');

        self::assertStringStartsWith('v1:', $encrypted);
        self::assertStringNotContainsString('lio_secret-token', $encrypted);
        self::assertStringNotContainsString('lio_secret-token', (string) base64_decode(substr($encrypted, 3), true));
    }

    public function testEachEncryptionUsesAFreshNonce(): void
    {
        $box = new SecretBox(self::key());

        self::assertNotSame($box->encrypt('same'), $box->encrypt('same'));
    }

    public function testTamperedCiphertextIsRejected(): void
    {
        $box = new SecretBox(self::key());
        $raw = (string) base64_decode(substr($box->encrypt('lio_secret-token'), 3), true);
        $raw[\strlen($raw) - 1] = \chr(\ord($raw[\strlen($raw) - 1]) ^ 1);

        $this->expectException(\UnexpectedValueException::class);
        $box->decrypt('v1:'.base64_encode($raw));
    }

    public function testAnotherKeyCannotDecrypt(): void
    {
        $encrypted = (new SecretBox(self::key()))->encrypt('lio_secret-token');

        $this->expectException(\UnexpectedValueException::class);
        (new SecretBox(self::key()))->decrypt($encrypted);
    }

    public function testMalformedValueIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new SecretBox(self::key()))->decrypt('v1:not-base64!!');
    }

    public function testKeyMustBe32Bytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SecretBox(base64_encode('too short'));
    }

    public function testEmptyKeyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SecretBox('');
    }

    public function testValuesUnderThePreviousKeyStillDecryptAndAreFlagged(): void
    {
        $oldKey = self::key();
        $newKey = self::key();
        $old = (new SecretBox($oldKey))->encrypt('lio_secret-token');
        $rotated = new SecretBox($newKey, $oldKey);

        self::assertSame('lio_secret-token', $rotated->decrypt($old));
        self::assertTrue($rotated->needsReencryption($old));
        self::assertFalse($rotated->needsReencryption($rotated->encrypt('lio_secret-token')));
    }

    public function testAMalformedPreviousKeyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SecretBox(self::key(), 'short');
    }

    private static function key(): string
    {
        return base64_encode(random_bytes(\SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }
}
