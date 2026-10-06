<?php

declare(strict_types=1);

namespace App\Security\Account;

use App\Entity\AccountDeletionCode;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Mailer\SyncMailer;
use App\Repository\AccountDeletionCodeRepository;
use App\Repository\UserRepository;
use App\Security\Audit\AuditLogger;
use App\Security\RefreshToken\RefreshTokenService;
use App\Security\TwoFactor\EmailCodeTwoFactorMethod;
use App\Security\UserAgent\UserAgentSummarizer;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Account deletion (docs/AUTH.md): a 6-digit code sent by email proves the request comes from the
 * mailbox owner (an OAuth-only account has no password to ask for); an account without a verified
 * email (Lichess only) proves it by a sign-in less than {@see RECENT_SIGN_IN_SECONDS} old instead.
 * Once confirmed, the account
 * is frozen for {@see GRACE_DAYS} days, every session closed, then purged by
 * {@see AccountPurger}. Signing in again during the grace period only allows to cancel it, or to
 * export the data.
 *
 * The code is HMAC'd with the app secret, like the 2FA code ({@see EmailCodeTwoFactorMethod}):
 * 10^6 values would be a lookup table away from a plain hash.
 */
final readonly class AccountDeletion
{
    public const GRACE_DAYS = 30;
    public const CODE_TTL_SECONDS = 600;
    public const MAX_ATTEMPTS = 5;
    public const MIN_RESEND_SECONDS = 30;
    public const RECENT_SIGN_IN_SECONDS = 600;
    public const METHOD_EMAIL = 'email';
    public const METHOD_RECENT_SIGN_IN = 'recent_sign_in';

    public function __construct(
        private AccountDeletionCodeRepository $codes,
        private UserRepository $users,
        private RefreshTokenService $refreshTokens,
        private AuditLogger $auditLogger,
        private SyncMailer $syncMailer,
        private MailerInterface $mailer,
        private RequestStack $requestStack,
        private UserAgentSummarizer $userAgentSummarizer,
        private ClockInterface $clock,
        #[Autowire('%env(MAILER_FROM_ADDRESS)%')]
        private string $fromAddress,
        #[Autowire('%env(FRONTEND_URL)%')]
        private string $frontendUrl,
        #[Autowire('%kernel.secret%')]
        private string $hmacSecret,
    ) {
    }

    /**
     * How $user confirms: a code by email, or a recent sign-in when the account has no verified email.
     *
     * @return self::METHOD_*
     */
    public function methodFor(User $user): string
    {
        return null !== $user->getEmail() && $user->isEmailVerified() ? self::METHOD_EMAIL : self::METHOD_RECENT_SIGN_IN;
    }

    /**
     * Whether a session signed in at $signedInAt is recent enough to confirm without a code.
     */
    public function isRecentSignIn(?\DateTimeImmutable $signedInAt): bool
    {
        return null !== $signedInAt && $this->now()->getTimestamp() - $signedInAt->getTimestamp() <= self::RECENT_SIGN_IN_SECONDS;
    }

    /**
     * Sends a new code, replacing the previous one.
     *
     * @return \DateTimeImmutable when the code expires
     *
     * @throws AccountDeletionException `frozen` (already scheduled), `no_email`, `too_soon`
     */
    public function sendCode(User $user): \DateTimeImmutable
    {
        if ($user->isFrozen()) {
            throw new AccountDeletionException(AccountDeletionException::FROZEN);
        }
        $email = $user->getEmail();
        if (null === $email || !$user->isEmailVerified()) {
            throw new AccountDeletionException(AccountDeletionException::NO_EMAIL);
        }
        $now = $this->now();
        $previous = $this->codes->findOneByUser($user);
        if (null !== $previous && $now->getTimestamp() - $previous->getCreatedAt()->getTimestamp() < self::MIN_RESEND_SECONDS) {
            throw new AccountDeletionException(AccountDeletionException::TOO_SOON);
        }

        $code = EmailCodeTwoFactorMethod::generateCode();
        $expiresAt = $now->modify(\sprintf('+%d seconds', self::CODE_TTL_SECONDS));
        $request = $this->requestStack->getCurrentRequest();
        // Delivered before being stored: if sending fails, no code is left behind.
        $this->syncMailer->send((new TemplatedEmail())
            ->from(new Address($this->fromAddress, 'Don\'t Stay Rooky'))
            ->to($email)
            ->subject('Confirmez la suppression de votre compte Don\'t Stay Rooky')
            ->htmlTemplate('emails/account_deletion_code.html.twig')
            ->textTemplate('emails/account_deletion_code.txt.twig')
            ->context([
                'code' => $code,
                'requestedAt' => $now,
                'device' => $this->userAgentSummarizer->summarize($request?->headers->get('User-Agent')),
                'ip' => $request?->getClientIp() ?? 'unknown',
                'graceDays' => self::GRACE_DAYS,
            ]));

        $this->codes->deleteForUser($user);
        $this->codes->save(new AccountDeletionCode($user, $this->hash($code), $expiresAt, $now));
        $this->auditLogger->log(AuditEventType::AccountDeletionCodeSent, $user);

        return $expiresAt;
    }

    /**
     * Checks the proof and schedules the deletion: the account is frozen and every session closed
     * (the caller is signed out too).
     *
     * @param string|null             $submittedCode the emailed code (account with a verified email)
     * @param \DateTimeImmutable|null $signedInAt    when the asking session signed in (account without one)
     *
     * @return \DateTimeImmutable when the account will be purged
     *
     * @throws AccountDeletionException `frozen`, `code_expired` (none, expired or out of
     *                                  attempts: ask a new one), `invalid_code`, `recent_sign_in_required`
     */
    public function confirm(User $user, #[\SensitiveParameter] ?string $submittedCode, ?\DateTimeImmutable $signedInAt): \DateTimeImmutable
    {
        if ($user->isFrozen()) {
            throw new AccountDeletionException(AccountDeletionException::FROZEN);
        }
        if (self::METHOD_RECENT_SIGN_IN === $this->methodFor($user)) {
            if (!$this->isRecentSignIn($signedInAt)) {
                throw new AccountDeletionException(AccountDeletionException::SIGN_IN_REQUIRED);
            }

            return $this->schedule($user, ['method' => self::METHOD_RECENT_SIGN_IN]);
        }
        $now = $this->now();
        $code = $this->codes->findOneByUser($user);
        if (null === $code || $code->getExpiresAt() < $now || !$this->codes->reserveAttempt($code, self::MAX_ATTEMPTS)) {
            throw new AccountDeletionException(AccountDeletionException::CODE_EXPIRED);
        }
        if (null === $submittedCode || !hash_equals($code->getCodeHash(), $this->hash($submittedCode))) {
            $this->auditLogger->log(AuditEventType::AccountDeletionCodeFailed, $user, ['attempts' => $code->getAttempts() + 1]);

            throw new AccountDeletionException(AccountDeletionException::INVALID_CODE);
        }

        $this->codes->deleteForUser($user);

        return $this->schedule($user, ['method' => self::METHOD_EMAIL]);
    }

    /**
     * @param array<string, string> $metadata
     */
    private function schedule(User $user, array $metadata): \DateTimeImmutable
    {
        $scheduledAt = $this->now()->modify(\sprintf('+%d days', self::GRACE_DAYS));
        $user->scheduleDeletion($scheduledAt)->bumpTokenVersion();
        $this->users->save($user);
        $this->auditLogger->log(AuditEventType::AccountDeletionScheduled, $user, [...$metadata, 'scheduledAt' => $scheduledAt->format(\DATE_ATOM)]);
        $this->notify($user, 'account_deletion_scheduled', 'Suppression de votre compte Don\'t Stay Rooky programmée', ['scheduledAt' => $scheduledAt]);

        // Last: the bulk revocation clears the entity manager, detaching $user.
        $this->refreshTokens->revokeAllSessions($user);

        return $scheduledAt;
    }

    /**
     * Cancels a scheduled deletion; nothing happens when there is none.
     */
    public function cancel(User $user): void
    {
        if (!$user->isFrozen()) {
            return;
        }
        $user->cancelDeletion();
        $this->users->save($user);
        $this->auditLogger->log(AuditEventType::AccountDeletionCancelled, $user);
        $this->notify($user, 'account_deletion_cancelled', 'Suppression de votre compte Don\'t Stay Rooky annulée', []);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function notify(User $user, string $template, string $subject, array $context): void
    {
        $email = $user->getEmail();
        if (null === $email) {
            return;
        }
        $this->mailer->send((new TemplatedEmail())
            ->from(new Address($this->fromAddress, 'Don\'t Stay Rooky'))
            ->to($email)
            ->subject($subject)
            ->htmlTemplate(\sprintf('emails/%s.html.twig', $template))
            ->textTemplate(\sprintf('emails/%s.txt.twig', $template))
            ->context([...$context, 'loginUrl' => $this->frontendUrl.'/#/login', 'graceDays' => self::GRACE_DAYS]));
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, $this->hmacSecret);
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
    }
}
