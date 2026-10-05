<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mailer;

use App\Mailer\MailFunctionTransport;
use App\Mailer\MailFunctionTransportFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mime\Email;

final class MailFunctionTransportTest extends TestCase
{
    /** @var list<array{to: string, subject: string, body: string, headers: string, params: string}> */
    private array $calls = [];

    public function testTheRenderedMessageIsHandedToMailInItsParts(): void
    {
        $this->transport(true)->send((new Email())
            ->from('ChessMate <noreply@chessmate.test>')
            ->to('alice@example.com')
            ->subject('Votre code de connexion ChessMate — à saisir')
            ->text('Votre code : 123456')
            ->html('<p>Votre code : <strong>123456</strong></p>'));

        self::assertCount(1, $this->calls);
        $call = $this->calls[0];
        self::assertSame('alice@example.com', $call['to']);
        // Encoded (non-ASCII), on one line: mail() refuses line breaks there.
        self::assertStringContainsString('=?utf-8?', $call['subject']);
        self::assertStringNotContainsString("\n", $call['subject']);
        self::assertStringNotContainsString("\r", $call['to']);
        // Every other header, the body's own included; never To or Subject twice.
        self::assertStringContainsString('From: ChessMate <noreply@chessmate.test>', $call['headers']);
        self::assertStringContainsString('MIME-Version: 1.0', $call['headers']);
        self::assertStringContainsString('Content-Type: multipart/alternative', $call['headers']);
        self::assertDoesNotMatchRegularExpression('/^(To|Subject):/mi', $call['headers']);
        self::assertStringEndsNotWith("\n", $call['headers']);
        self::assertStringContainsString('Votre code : 123456', $call['body']);
        self::assertStringNotContainsString('From:', $call['body']);
        // Bounces come back to the sender.
        self::assertSame('-fnoreply@chessmate.test', $call['params']);
    }

    public function testARefusalIsAnError(): void
    {
        $this->expectException(TransportException::class);

        $this->transport(false)->send((new Email())->from('noreply@chessmate.test')->to('alice@example.com')->subject('x')->text('y'));
    }

    public function testTheFactoryAnswersItsScheme(): void
    {
        $factory = new MailFunctionTransportFactory();

        self::assertTrue($factory->supports(Dsn::fromString('mailfunction://default')));
        self::assertFalse($factory->supports(Dsn::fromString('smtp://localhost')));
        self::assertSame('mailfunction://default', (string) $factory->create(Dsn::fromString('mailfunction://default')));
    }

    private function transport(bool $accepted): MailFunctionTransport
    {
        return new MailFunctionTransport(function (string $to, string $subject, string $body, string $headers, string $params) use ($accepted): bool {
            $this->calls[] = ['to' => $to, 'subject' => $subject, 'body' => $body, 'headers' => $headers, 'params' => $params];

            return $accepted;
        });
    }
}
