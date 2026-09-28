<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\TwoFactor;

use App\Entity\MfaChallenge;
use App\Entity\User;
use App\Mailer\SyncMailer;
use App\Security\TwoFactor\EmailCodeTwoFactorMethod;
use App\Security\UserAgent\UserAgentSummarizer;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

final class EmailCodeTwoFactorMethodTest extends TestCase
{
    private const SECRET = 'unit-test-secret';

    /** @var list<RawMessage> */
    private array $sent = [];

    public function testGeneratedCodesAreAlwaysSixDigits(): void
    {
        for ($i = 0; $i < 1000; ++$i) {
            self::assertMatchesRegularExpression('/^\d{6}$/', EmailCodeTwoFactorMethod::generateCode());
        }
    }

    public function testGeneratedCodesAreNotPredictablyRepeated(): void
    {
        $codes = [];
        for ($i = 0; $i < 200; ++$i) {
            $codes[] = EmailCodeTwoFactorMethod::generateCode();
        }

        // 200 draws out of 10^6: a handful of collisions at most, never a constant generator.
        self::assertGreaterThan(190, \count(array_unique($codes)));
    }

    public function testBeginStoresOnlyAKeyedHashAndMailsThePlainCode(): void
    {
        $challenge = $this->newChallenge();

        $this->method()->begin($challenge);

        $code = $this->sentCode();
        self::assertSame(hash_hmac('sha256', $code, self::SECRET), $challenge->getCodeHash());
        self::assertNotSame(hash('sha256', $code), $challenge->getCodeHash(), 'An unkeyed hash of a 6-digit code is trivially reversible.');
    }

    public function testVerifyAcceptsOnlyTheSentCode(): void
    {
        $method = $this->method();
        $challenge = $this->newChallenge();
        $method->begin($challenge);
        $code = $this->sentCode();

        self::assertTrue($method->verify($challenge, $code));
        self::assertFalse($method->verify($challenge, '000000' === $code ? '000001' : '000000'));
        self::assertFalse($method->verify($challenge, ''));
    }

    public function testVerifyFailsClosedWithoutACodeHash(): void
    {
        self::assertFalse($this->method()->verify($this->newChallenge(), '123456'));
    }

    public function testAHashFromAnotherSecretDoesNotVerify(): void
    {
        $challenge = $this->newChallenge();
        $challenge->setCodeHash(hash_hmac('sha256', '123456', 'another-secret'));

        self::assertFalse($this->method()->verify($challenge, '123456'));
    }

    public function testResendingReplacesThePreviousCode(): void
    {
        $method = $this->method();
        $challenge = $this->newChallenge();
        $method->begin($challenge);
        $firstCode = $this->sentCode();

        $challenge->restart(new \DateTimeImmutable('+10 minutes'));
        $method->begin($challenge);
        $secondCode = $this->sentCode();

        self::assertTrue($method->verify($challenge, $secondCode));
        if ($firstCode !== $secondCode) {
            self::assertFalse($method->verify($challenge, $firstCode));
        }
    }

    public function testSupportsOnlyUsersWithAVerifiedEmail(): void
    {
        $method = $this->method();

        $verified = (new User())->setEmail('alice@example.com')->markEmailVerified();
        $unverified = (new User())->setEmail('bob@example.com');
        $oauthOnly = new User();

        self::assertTrue($method->supports($verified));
        self::assertFalse($method->supports($unverified));
        self::assertFalse($method->supports($oauthOnly));
    }

    private function method(): EmailCodeTwoFactorMethod
    {
        $transport = $this->createMock(TransportInterface::class);
        $transport->method('send')->willReturnCallback(function (RawMessage $message): null {
            $this->sent[] = $message;

            return null;
        });

        return new EmailCodeTwoFactorMethod(new SyncMailer($transport), new UserAgentSummarizer(), 'no-reply@example.com', self::SECRET);
    }

    private function newChallenge(): MfaChallenge
    {
        $user = (new User())->setEmail('alice@example.com')->markEmailVerified();

        return new MfaChallenge($user, 'email', hash('sha256', 'pending'), new \DateTimeImmutable('+10 minutes'), '203.0.113.7', null);
    }

    private function sentCode(): string
    {
        $message = end($this->sent);
        self::assertInstanceOf(TemplatedEmail::class, $message);
        $code = $message->getContext()['code'] ?? null;
        self::assertIsString($code);

        return $code;
    }
}
