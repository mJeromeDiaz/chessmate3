<?php

declare(strict_types=1);

namespace App\Security\Password;

use App\Entity\User;
use App\Enum\AuditEventType;
use App\Repository\UserRepository;
use App\Security\Audit\AuditLogger;
use App\Security\EmailVerification\EmailVerifier;
use App\Security\UserAgent\UserAgentSummarizer;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Adds a first password to an account created through OAuth.
 *
 * Password sign-in needs a verified email (identifier + where the 2FA code goes):
 * - the account has one: the password works right away, and the address is notified;
 * - it has none (Lichess gives no email): the address the user typed becomes a pending email,
 *   confirmed by the usual verification link; until then the password can't be used. An address
 *   already owned by another account is never revealed: the answer is the same, nothing changes
 *   on this account, and the owner gets an informational email.
 *
 * No re-authentication: the access token is enough (validated choice — residual risk documented in
 * docs/SECURITY.md).
 */
final readonly class PasswordAdder
{
    public const RESULT_ADDED = 'added';
    public const RESULT_VERIFICATION_SENT = 'verification_sent';

    public function __construct(
        private UserPasswordHasherInterface $passwordHasher,
        private UserRepository $userRepository,
        private EmailVerifier $emailVerifier,
        private AuditLogger $auditLogger,
        private MailerInterface $mailer,
        private RequestStack $requestStack,
        private UserAgentSummarizer $userAgentSummarizer,
        #[Autowire('%env(MAILER_FROM_ADDRESS)%')]
        private string $fromAddress,
        #[Autowire('%env(FRONTEND_URL)%')]
        private string $frontendUrl,
    ) {
    }

    /**
     * @return self::RESULT_* what the user has to do next, if anything
     *
     * @throws \DomainException          if the account can already sign in with a password
     * @throws \InvalidArgumentException if an email is needed and none was given
     */
    public function add(User $user, #[\SensitiveParameter] string $plainPassword, ?string $requestedEmail): string
    {
        if ($user->canSignInWithPassword()) {
            throw new \DomainException('This account already has a password.');
        }

        // Hashed in every branch, so the "address taken" case costs the same time.
        $hash = $this->passwordHasher->hashPassword($user, $plainPassword);

        if (null !== $user->getEmail() && $user->isEmailVerified()) {
            $user->setPassword($hash);
            $this->userRepository->save($user);
            $this->auditLogger->log(AuditEventType::PasswordAdded, $user);
            $this->notifyAdded($user, (string) $user->getEmail());

            return self::RESULT_ADDED;
        }

        $email = null !== $requestedEmail ? strtolower(trim($requestedEmail)) : '';

        if ('' === $email) {
            throw new \InvalidArgumentException('An email address is required.');
        }

        $owner = $this->userRepository->findOneByEmail($email);

        if (null !== $owner) {
            if (!$owner->getId()->equals($user->getId())) {
                $this->auditLogger->log(AuditEventType::PasswordAdded, $user, ['outcome' => 'email_in_use']);
                $this->notifyAddressInUse($email);
            }

            return self::RESULT_VERIFICATION_SENT;
        }

        $user->setPassword($hash);
        $user->setPendingEmail($email);
        $this->userRepository->save($user);
        $this->emailVerifier->sendVerificationEmail($user);
        $this->auditLogger->log(AuditEventType::PasswordAdded, $user, ['outcome' => 'pending_email_verification']);

        return self::RESULT_VERIFICATION_SENT;
    }

    private function notifyAdded(User $user, string $email): void
    {
        $request = $this->requestStack->getCurrentRequest();

        $this->mailer->send((new TemplatedEmail())
            ->from(new Address($this->fromAddress, 'ChessMate'))
            ->to($email)
            ->subject('Un mot de passe a été ajouté à votre compte ChessMate')
            ->htmlTemplate('emails/password_added.html.twig')
            ->textTemplate('emails/password_added.txt.twig')
            ->context([
                'addedAt' => new \DateTimeImmutable(),
                'device' => $this->userAgentSummarizer->summarize($request?->headers->get('User-Agent')),
                'ip' => $request?->getClientIp() ?? 'unknown',
                'forgotPasswordUrl' => $this->frontendUrl.'/#/forgot-password',
                'profileUrl' => $this->frontendUrl.'/#/profile',
            ]));
    }

    private function notifyAddressInUse(string $email): void
    {
        $this->mailer->send((new TemplatedEmail())
            ->from(new Address($this->fromAddress, 'ChessMate'))
            ->to($email)
            ->subject('Votre adresse email ChessMate')
            ->htmlTemplate('emails/email_already_in_use.html.twig')
            ->textTemplate('emails/email_already_in_use.txt.twig')
            ->context([
                'attemptedAt' => new \DateTimeImmutable(),
                'loginUrl' => $this->frontendUrl.'/#/login',
            ]));
    }
}
