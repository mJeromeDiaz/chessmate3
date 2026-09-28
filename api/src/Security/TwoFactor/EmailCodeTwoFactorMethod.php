<?php

declare(strict_types=1);

namespace App\Security\TwoFactor;

use App\Entity\MfaChallenge;
use App\Entity\User;
use App\Mailer\SyncMailer;
use App\Security\UserAgent\UserAgentSummarizer;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;

/**
 * A 6-digit code sent by email.
 *
 * The code has only 10^6 possible values, so a plain hash would be a lookup table away from
 * reversible on a leaked database; it's HMAC'd with the app secret instead, which an attacker with
 * read access to the database alone does not have.
 */
final readonly class EmailCodeTwoFactorMethod implements TwoFactorMethodInterface
{
    public const NAME = 'email';

    public function __construct(
        private SyncMailer $mailer,
        private UserAgentSummarizer $userAgentSummarizer,
        #[Autowire('%env(MAILER_FROM_ADDRESS)%')]
        private string $fromAddress,
        #[Autowire('%kernel.secret%')]
        private string $hmacSecret,
    ) {
    }

    #[\Override]
    public function getName(): string
    {
        return self::NAME;
    }

    #[\Override]
    public function supports(User $user): bool
    {
        return null !== $user->getEmail() && $user->isEmailVerified();
    }

    #[\Override]
    public function begin(MfaChallenge $challenge): void
    {
        $code = self::generateCode();
        $challenge->setCodeHash($this->hashCode($code));

        $message = (new TemplatedEmail())
            ->from(new Address($this->fromAddress, 'ChessMate'))
            ->to((string) $challenge->getUser()->getEmail())
            ->subject('Votre code de connexion ChessMate')
            ->htmlTemplate('emails/mfa_code.html.twig')
            ->textTemplate('emails/mfa_code.txt.twig')
            ->context([
                'code' => $code,
                'requestedAt' => $challenge->getLastSentAt(),
                'device' => $this->userAgentSummarizer->summarize($challenge->getUserAgent()),
                'ip' => $challenge->getIp() ?? 'unknown',
            ]);

        $this->mailer->send($message);
    }

    #[\Override]
    public function canResend(): bool
    {
        return true;
    }

    #[\Override]
    public function verify(MfaChallenge $challenge, string $submittedCode): bool
    {
        $expectedHash = $challenge->getCodeHash();

        return null !== $expectedHash && hash_equals($expectedHash, $this->hashCode($submittedCode));
    }

    /**
     * Uniformly distributed over 000000–999999 ({@see random_int()} is a CSPRNG, unlike rand()).
     */
    public static function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', \STR_PAD_LEFT);
    }

    private function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, $this->hmacSecret);
    }
}
