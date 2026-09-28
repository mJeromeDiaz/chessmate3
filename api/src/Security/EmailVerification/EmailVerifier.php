<?php

declare(strict_types=1);

namespace App\Security\EmailVerification;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mime\Address;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

/**
 * Generates and sends the email-verification signed link, and validates it back.
 *
 * The bundle's signed URL (HMAC over user id + email + expiry, via Symfony's UriSigner) is the
 * entire security mechanism here — there is no separate token stored in the database. This is
 * intentionally different from password reset, which uses symfonycasts/reset-password-bundle's
 * selector + hashed-verifier scheme (see docs/SECURITY.md for why each bundle uses the mechanism it
 * does).
 */
final readonly class EmailVerifier
{
    public function __construct(
        private VerifyEmailHelperInterface $verifyEmailHelper,
        private MailerInterface $mailer,
        #[Autowire('%env(MAILER_FROM_ADDRESS)%')]
        private string $fromAddress,
    ) {
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function sendVerificationEmail(User $user): void
    {
        $email = $this->addressToVerify($user);

        $signature = $this->verifyEmailHelper->generateSignature(
            'app_verify_email',
            $user->getId()->toRfc4122(),
            $email,
            ['id' => $user->getId()->toRfc4122()],
        );

        $message = (new TemplatedEmail())
            ->from(new Address($this->fromAddress, 'ChessMate'))
            ->to($email)
            ->subject('Confirmez votre adresse email')
            ->htmlTemplate('emails/verify_email.html.twig')
            ->textTemplate('emails/verify_email.txt.twig')
            ->context([
                'signedUrl' => $signature->getSignedUrl(),
                'expiresInMinutes' => max(1, (int) ceil(($signature->getExpiresAt()->getTimestamp() - time()) / 60)),
            ]);

        $this->mailer->send($message);
    }

    /**
     * @throws VerifyEmailExceptionInterface
     */
    public function confirmEmail(Request $request, User $user): void
    {
        $this->verifyEmailHelper->validateEmailConfirmationFromRequest(
            $request,
            $user->getId()->toRfc4122(),
            $this->addressToVerify($user),
        );

        if (null !== $user->getPendingEmail()) {
            $user->confirmPendingEmail();
        } else {
            $user->markEmailVerified();
        }
    }

    /**
     * The pending address if there is one (a password being added), otherwise the account's email.
     * The signature covers it, so a link sent for one address can't confirm another.
     */
    public function addressToVerify(User $user): string
    {
        return $user->getPendingEmail() ?? (string) $user->getEmail();
    }
}
