<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\UserAgent;

use App\Security\UserAgent\UserAgentSummarizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserAgentSummarizerTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function userAgents(): iterable
    {
        yield 'no header' => [null, 'Unknown device'];
        yield 'blank header' => ['   ', 'Unknown device'];
        yield 'chrome windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36', 'Chrome on Windows'];
        yield 'edge windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 Edg/128.0.0.0', 'Edge on Windows'];
        yield 'firefox linux' => ['Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0', 'Firefox on Linux'];
        yield 'safari macos' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14_6) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Safari/605.1.15', 'Safari on macOS'];
        // iOS UAs contain "like Mac OS X": must not be reported as macOS.
        yield 'safari iphone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1', 'Safari on iOS'];
        yield 'chrome ipad' => ['Mozilla/5.0 (iPad; CPU OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/128.0.6613.98 Mobile/15E148 Safari/604.1', 'Chrome on iOS'];
        // Android UAs contain "Linux": must not be reported as Linux.
        yield 'chrome android' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Mobile Safari/537.36', 'Chrome on Android'];
        yield 'unknown client' => ['curl/8.5.0', 'a browser on an unknown OS'];
    }

    #[DataProvider('userAgents')]
    public function testSummarize(?string $userAgent, string $expected): void
    {
        self::assertSame($expected, (new UserAgentSummarizer())->summarize($userAgent));
    }

    /**
     * @return iterable<string, array{?string, array{browser: ?string, os: ?string, form: ?string}}>
     */
    public static function descriptions(): iterable
    {
        yield 'no header' => [null, ['browser' => null, 'os' => null, 'form' => null]];
        yield 'chrome macos' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36', ['browser' => 'Chrome', 'os' => 'macOS', 'form' => 'desktop']];
        yield 'safari iphone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1', ['browser' => 'Safari', 'os' => 'iOS', 'form' => 'phone']];
        // iPad UAs say "Mobile" too: still a tablet.
        yield 'chrome ipad' => ['Mozilla/5.0 (iPad; CPU OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/128.0.6613.98 Mobile/15E148 Safari/604.1', ['browser' => 'Chrome', 'os' => 'iOS', 'form' => 'tablet']];
        yield 'android phone' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Mobile Safari/537.36', ['browser' => 'Chrome', 'os' => 'Android', 'form' => 'phone']];
        yield 'android tablet' => ['Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36', ['browser' => 'Chrome', 'os' => 'Android', 'form' => 'tablet']];
        yield 'unknown client' => ['curl/8.5.0', ['browser' => null, 'os' => null, 'form' => 'desktop']];
    }

    /**
     * @param array{browser: ?string, os: ?string, form: ?string} $expected
     */
    #[DataProvider('descriptions')]
    public function testDescribe(?string $userAgent, array $expected): void
    {
        self::assertSame($expected, (new UserAgentSummarizer())->describe($userAgent));
    }
}
