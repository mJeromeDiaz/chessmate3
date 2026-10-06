<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\Trace;

use App\Security\Trace\Redactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

final class RedactorTest extends TestCase
{
    /**
     * Request fields of src/Dto that the trace log may keep in clear. Every other field must be
     * masked: a new field fails {@see testEveryRequestFieldIsClassified} until it is listed here or
     * recognized by the Redactor.
     */
    private const HARMLESS_DTO_FIELDS = [
        'avatar', 'boardTheme', 'displayName', 'email', 'handle', 'moveSound', 'publicProfile',
        'theme', 'timezone', 'trustDevice',
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function sensitiveKeys(): iterable
    {
        foreach ([
            'password', 'currentPassword', 'newPassword', 'new_password', 'PASSWORD', 'code',
            'pendingToken', 'token', 'refresh_token', 'accessToken', 'invitationKey', 'key', 'state',
            'signature', 'auth', 'p256dh', 'endpoint', 'clientSecret',
        ] as $key) {
            yield $key => [$key];
        }
    }

    #[DataProvider('sensitiveKeys')]
    public function testSensitiveKeyIsMasked(string $key): void
    {
        self::assertSame([$key => Redactor::MASK], (new Redactor())->redact([$key => 'secret value']));
    }

    public function testHarmlessKeysAreKept(): void
    {
        $data = ['email' => 'alice@example.com', 'trustDevice' => true, 'moves' => ['e4', 'e5'], 'rating' => 1500];

        self::assertSame($data, (new Redactor())->redact($data));
    }

    public function testNestedSecretsAreMasked(): void
    {
        $redacted = (new Redactor())->redact([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc',
            'keys' => ['p256dh' => 'BPk...', 'auth' => 'xyz'],
            'items' => [['name' => 'a', 'password' => 'p']],
        ]);

        self::assertSame([
            'endpoint' => Redactor::MASK,
            'keys' => ['p256dh' => Redactor::MASK, 'auth' => Redactor::MASK],
            'items' => [['name' => 'a', 'password' => Redactor::MASK]],
        ], $redacted);
    }

    public function testASensitiveKeyHoldingAnArrayIsMaskedWhole(): void
    {
        self::assertSame(['token' => Redactor::MASK], (new Redactor())->redact(['token' => ['a' => 'b']]));
    }

    public function testEveryRequestFieldIsClassified(): void
    {
        $redactor = new Redactor();
        $checked = 0;

        foreach ((new Finder())->files()->in(\dirname(__DIR__, 4).'/src/Dto')->name('*.php') as $file) {
            $class = 'App\\Dto\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            self::assertTrue(class_exists($class), $class);

            foreach ((new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                if ($property->isStatic()) {
                    continue;
                }

                $name = $property->getName();
                self::assertSame(
                    !\in_array($name, self::HARMLESS_DTO_FIELDS, true),
                    $redactor->isSensitive($name),
                    \sprintf('%s::$%s: mask it in the Redactor, or list it as harmless here.', $class, $name),
                );
                ++$checked;
            }
        }

        self::assertGreaterThan(10, $checked);
    }
}
