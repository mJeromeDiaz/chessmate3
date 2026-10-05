<?php

declare(strict_types=1);

namespace App\Tests\Unit\EarlyAccess;

use App\EarlyAccess\Invitation\KeyGenerator;
use PHPUnit\Framework\TestCase;

final class KeyGeneratorTest extends TestCase
{
    public function testKeysAre32AlphanumericCharactersAndUnique(): void
    {
        $generator = new KeyGenerator();
        $keys = [];
        for ($i = 0; $i < 2000; ++$i) {
            $key = $generator->generate();
            self::assertMatchesRegularExpression('/^[A-Za-z0-9]{32}$/', $key);
            $keys[$key] = true;
        }
        self::assertCount(2000, $keys);

        // Every character of the alphabet shows up (uniform draw, not hex).
        self::assertCount(62, array_unique(str_split(implode('', array_keys($keys)))));
    }

    public function testHashHintAndShape(): void
    {
        $key = 'AbCdEfGhIjKlMnOpQrStUvWxYz012345';
        self::assertSame(hash('sha256', $key), KeyGenerator::hash($key));
        self::assertSame('AbCd', KeyGenerator::hint($key));
        self::assertTrue(KeyGenerator::isWellFormed($key));
        self::assertFalse(KeyGenerator::isWellFormed(substr($key, 1)));
        self::assertFalse(KeyGenerator::isWellFormed(substr($key, 1).'-'));
        self::assertFalse(KeyGenerator::isWellFormed($key."\n"));
    }
}
