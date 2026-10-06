<?php

declare(strict_types=1);

namespace App\Security\Password;

use App\Entity\User;
use App\Enum\AuditEventType;
use App\Repository\MfaChallengeRepository;
use App\Repository\UserRepository;
use App\Security\Audit\AuditLogger;
use App\Security\RefreshToken\RefreshTokenService;
use App\Security\TrustedDevice\TrustedDeviceService;
use App\Security\TwoFactor\MfaFailureLimiter;
use App\Security\UserAgent\UserAgentSummarizer;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Sets a new password and cuts off everything that was authenticated under the old one: every
 * refresh-token session, every access token (via the token version), every trusted device, every
 * login still waiting for its 2FA code. Then notifies the account's email address.
 *
 * Shared by the password change (profile) and the password reset (emailed link).
 *
 * Issuing a new session for the caller, if any, is left to the caller — this leaves the user with
 * no session at all.
 */
final readonly class PasswordChanger
{
    public function __construct(
        private UserPasswordHasherInterface $passwordHasher,
        private UserRepository $userRepository,
        private MfaChallengeRepository $mfaChallengeRepository,
        private RefreshTokenService $refreshTokenService,
        private TrustedDeviceService $trustedDeviceService,
        private AuditLogger $auditLogger,
        private MailerInterface $mailer,
        private RequestStack $requestStack,
        private UserAgentSummarizer $userAgentSummarizer,
        private MfaFailureLimiter $mfaFailureLimiter,
        #[Autowire('%env(MAILER_FROM_ADDRESS)%')]
        private string $fromAddress,
        #[Autowire('%env(FRONTEND_URL)%')]
        private string $frontendUrl,
    ) {
    }

    /**
     * @param AuditEventType::PasswordChanged|AuditEventType::PasswordResetCompleted $auditEvent
     */
    public function change(User $user, #[\SensitiveParameter] string $newPlainPassword, AuditEventType $auditEvent = AuditEventType::PasswordChanged): void
    {
        $user->setPassword($this->passwordHasher->hashPassword($user, $newPlainPassword));
        $user->bumpTokenVersion();
        $this->userRepository->save($user);

        $this->trustedDeviceService->revokeAllForUser($user);
        $this->mfaChallengeRepository->invalidateActiveForUser($user);
        // Whoever was guessing codes had the old password; the owner starts afresh.
        $this->mfaFailureLimiter->reset($user);
        $this->auditLogger->log($auditEvent, $user);
        $this->sendNotification($user);

        // Last: the bulk revocation clears the entity manager, detaching $user.
        $this->refreshTokenService->revokeAllSessions($user);
    }

    private function sendNotification(User $user): void
    {
        $email = $user->getEmail();

        if (null === $email) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();

        $message = (new TemplatedEmail())
            ->from(new Address($this->fromAddress, 'Don\'t Stay Rooky'))
            ->to($email)
            ->subject('Votre mot de passe Don\'t Stay Rooky a été modifié')
            ->htmlTemplate('emails/password_changed.html.twig')
            ->textTemplate('emails/password_changed.txt.twig')
            ->context([
                'changedAt' => new \DateTimeImmutable(),
                'device' => $this->userAgentSummarizer->summarize($request?->headers->get('User-Agent')),
                'ip' => $request?->getClientIp() ?? 'unknown',
                'forgotPasswordUrl' => $this->frontendUrl.'/#/forgot-password',
            ]);

        $this->mailer->send($message);
    }
}
