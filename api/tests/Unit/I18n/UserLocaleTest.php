<?php

declare(strict_types=1);

namespace App\Tests\Unit\I18n;

use App\Entity\User;
use App\Enum\Locale;
use App\I18n\UserLocale;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class UserLocaleTest extends TestCase
{
    public function testTheAccountsChoiceComesFirstThenTheRequestThenEnglish(): void
    {
        $requests = new RequestStack();
        $locales = new UserLocale($requests);
        $user = new User();

        // No request (a worker, a cron) and no choice: English, never French.
        self::assertSame('en', $locales->of($user));
        self::assertSame('en', $locales->of());

        $request = new Request();
        $request->setLocale('fr');
        $requests->push($request);
        self::assertSame('fr', $locales->of($user));
        self::assertSame('fr', $locales->of());

        $user->setLocale(Locale::English);
        self::assertSame('en', $locales->of($user));
    }

    public function testALanguageTheSiteDoesNotSpeakIsEnglish(): void
    {
        $requests = new RequestStack();
        $request = new Request();
        $request->setLocale('de');
        $requests->push($request);

        self::assertSame('en', (new UserLocale($requests))->ofRequest());
    }
}
