<?php

declare(strict_types=1);

namespace App\Tests\Functional\I18n;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The language of a request (docs/I18N.md, § 5): its Accept-Language among the enabled locales,
 * English otherwise.
 */
final class RequestLocaleTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string|null, string}>
     */
    public static function headers(): iterable
    {
        yield 'none' => [null, 'en'];
        yield 'French' => ['fr-FR,fr;q=0.9,en;q=0.8', 'fr'];
        yield 'English' => ['en-US,en;q=0.9', 'en'];
        yield 'a language the site does not speak' => ['de-DE,de;q=0.9', 'en'];
        yield 'French second' => ['de-DE,fr;q=0.8', 'fr'];
    }

    #[DataProvider('headers')]
    public function testTheRequestTakesItsAcceptLanguage(?string $header, string $locale): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/profile', server: null === $header ? [] : ['HTTP_ACCEPT_LANGUAGE' => $header]);

        self::assertSame($locale, $client->getRequest()->getLocale());
    }
}
