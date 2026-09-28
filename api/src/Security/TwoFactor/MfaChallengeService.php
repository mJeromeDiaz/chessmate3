<?php

declare(strict_types=1);

namespace App\Security\TwoFactor;

use App\Entity\MfaChallenge;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Repository\MfaChallengeRepository;
use App\Security\Audit\AuditLogger;
use App\Security\TwoFactor\Exception\MfaChallengeNotFoundException;
use App\Security\TwoFactor\Exception\MfaCodeInvalidException;
use App\Security\TwoFactor\Exception\MfaResendTooSoonException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Orchestrates the method-independent lifecycle of a 2FA step: the mfa_pending token, expiry,
 * the attempt cap, single use and resends. How a code is produced and checked is delegated to a
 * {@see TwoFactorMethodInterface}.
 *
 * The pending token (256 bits of {@see random_bytes()}) is stored as plain SHA-256 — its entropy
 * alone makes it infeasible to invert, the same reasoning as the refresh token's hash
 * ({@see \App\Entity\RefreshToken}).
 */
final readonly class MfaChallengeService
{
    private const CODE_TTL_SECONDS = 600;
    private const MIN_RESEND_INTERVAL_SECONDS = 30;
    /**
     * Hard ceiling on a pending token's lifetime, however many times the code is resent: each
     * resend gives the new code a fresh 10 minutes, but never past this point — otherwise resends
     * would keep one "very short-lived" mfa_pending token alive for as long as the resend rate
     * limit allows.
     */
    private const MAX_CHALLENGE_LIFETIME_SECONDS = 1800;

    /**
     * @param iterable<TwoFactorMethodInterface> $methods in priority order
     */
    public function __construct(
        private MfaChallengeRepository $repository,
        #[AutowireIterator(TwoFactorMethodInterface::TAG)]
        private iterable $methods,
        private AuditLogger $auditLogger,
        private MfaFailureLimiter $failureLimiter,
    ) {
    }

    /**
     * Starts a new challenge for $user and invalidates any previous one still pending, so only the
     * code from the most recent login attempt works.
     *
     * @throws \LogicException if no 2FA method supports this user
     */
    public function create(User $user, ?string $ip, ?string $userAgent): CreatedMfaChallenge
    {
        $method = $this->resolveMethodFor($user);
        $pendingToken = bin2hex(random_bytes(32));
        $expiresAt = new \DateTimeImmutable(sprintf('+%d seconds', self::CODE_TTL_SECONDS));

        $this->repository->invalidateActiveForUser($user);

        $challenge = new MfaChallenge($user, $method->getName(), $this->hashPendingToken($pendingToken), $expiresAt, $ip, $userAgent);
        // Delivered before being persisted: if sending fails, no orphan challenge is left behind.
        $method->begin($challenge);
        $this->repository->save($challenge);

        $this->auditLogger->log(AuditEventType::MfaCodeSent, $user, ['method' => $method->getName()]);

        return new CreatedMfaChallenge($pendingToken, $method->getName(), $expiresAt);
    }

    /**
     * @throws MfaChallengeNotFoundException
     * @throws MfaResendTooSoonException
     */
    public function resend(string $presentedPendingToken): CreatedMfaChallenge
    {
        $challenge = $this->findActiveOrFail($presentedPendingToken);
        $method = $this->methodNamed($challenge->getMethod());

        if (!$method->canResend()) {
            throw new MfaChallengeNotFoundException();
        }

        $secondsSinceLastSent = (new \DateTimeImmutable())->getTimestamp() - $challenge->getLastSentAt()->getTimestamp();

        if ($secondsSinceLastSent < self::MIN_RESEND_INTERVAL_SECONDS) {
            throw new MfaResendTooSoonException();
        }

        $expiresAt = min(
            new \DateTimeImmutable(sprintf('+%d seconds', self::CODE_TTL_SECONDS)),
            $challenge->getCreatedAt()->add(new \DateInterval(sprintf('PT%dS', self::MAX_CHALLENGE_LIFETIME_SECONDS))),
        );
        $challenge->restart($expiresAt);
        $method->begin($challenge);
        $this->repository->save($challenge);

        $this->auditLogger->log(AuditEventType::MfaCodeSent, $challenge->getUser(), ['method' => $method->getName(), 'resent' => true]);

        return new CreatedMfaChallenge($presentedPendingToken, $method->getName(), $expiresAt);
    }

    /**
     * @throws MfaChallengeNotFoundException
     * @throws MfaCodeInvalidException
     */
    public function verify(string $presentedPendingToken, string $submittedCode): User
    {
        $challenge = $this->findActiveOrFail($presentedPendingToken);
        $method = $this->methodNamed($challenge->getMethod());

        // Account over its budget of wrong codes: no code is even compared, the right one included,
        // and the answer is the same as for an expired login.
        if ($this->failureLimiter->isBlocked($challenge->getUser())) {
            $this->repository->invalidateActiveForUser($challenge->getUser());

            throw new MfaChallengeNotFoundException();
        }

        // Spend the attempt before comparing, atomically — see MfaChallengeRepository::reserveAttempt().
        if (!$this->repository->reserveAttempt($challenge)) {
            throw new MfaChallengeNotFoundException();
        }

        if (!$method->verify($challenge, $submittedCode)) {
            $this->failureLimiter->recordFailure($challenge->getUser());
            $this->auditLogger->log(AuditEventType::MfaCodeFailed, $challenge->getUser(), [
                'attempts' => $challenge->getAttempts(),
            ]);

            if ($this->repository->lockIfExhausted($challenge)) {
                $this->auditLogger->log(AuditEventType::MfaPendingExpired, $challenge->getUser(), [
                    'reason' => 'too_many_attempts',
                ]);
            }

            throw new MfaCodeInvalidException();
        }

        if (!$this->repository->consumeIfActive($challenge)) {
            throw new MfaChallengeNotFoundException();
        }

        $this->auditLogger->log(AuditEventType::MfaCodeSuccess, $challenge->getUser());

        return $challenge->getUser();
    }

    /**
     * @throws MfaChallengeNotFoundException
     */
    private function findActiveOrFail(string $presentedPendingToken): MfaChallenge
    {
        $challenge = $this->repository->findOneByPendingTokenHash($this->hashPendingToken($presentedPendingToken));

        if (null === $challenge || !$challenge->isActive()) {
            throw new MfaChallengeNotFoundException();
        }

        return $challenge;
    }

    private function resolveMethodFor(User $user): TwoFactorMethodInterface
    {
        foreach ($this->methods as $method) {
            if ($method->supports($user)) {
                return $method;
            }
        }

        throw new \LogicException('No two-factor method supports this user.');
    }

    private function methodNamed(string $name): TwoFactorMethodInterface
    {
        foreach ($this->methods as $method) {
            if ($name === $method->getName()) {
                return $method;
            }
        }

        throw new \LogicException(sprintf('Unknown two-factor method "%s".', $name));
    }

    private function hashPendingToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
