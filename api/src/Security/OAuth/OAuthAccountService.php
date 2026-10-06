<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use App\Entity\AuthIdentity;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Repository\AuthIdentityRepository;
use App\Repository\UserRepository;
use App\Security\Audit\AuditLogger;
use App\Security\OAuth\Exception\OAuthFlowException;
use App\Security\Registration\RegistrationGateInterface;
use App\Security\Registration\RegistrationRefusedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * What an {@see ExternalIdentity} means for our accounts.
 *
 * The one rule everything here follows: an identity is never attached to an existing account
 * because the emails match. Linking only happens through {@see self::link()}, i.e. a flow started by
 * the already signed-in owner of the account.
 */
final readonly class OAuthAccountService
{
    public function __construct(
        private AuthIdentityRepository $authIdentityRepository,
        private UserRepository $userRepository,
        private AuditLogger $auditLogger,
        private MailerInterface $mailer,
        private OAuthTokenVault $tokenVault,
        private RegistrationGateInterface $registrationGate,
        private EntityManagerInterface $entityManager,
        #[Autowire('%env(MAILER_FROM_ADDRESS)%')]
        private string $fromAddress,
        #[Autowire('%env(FRONTEND_URL)%')]
        private string $frontendUrl,
    ) {
    }

    /**
     * The user to sign in: the one this identity is linked to, or a brand new account, opened with
     * the invitation the flow started with (spent in the same transaction).
     *
     * @param string|null $registrationTicket the flow's admitted invitation ({@see RegistrationGateInterface})
     *
     * @return array{User, bool} the user, and whether the account was just created
     *
     * @throws OAuthFlowException ACCOUNT_EXISTS if the identity is unknown but its verified email
     *                            belongs to an existing account (the invitation is kept);
     *                            INVITATION_* if a new account has no usable invitation
     */
    public function resolveLogin(ExternalIdentity $identity, ?string $registrationTicket = null): array
    {
        $linked = $this->authIdentityRepository->findOneByProviderAndUserId($identity->provider, $identity->providerUserId);

        if (null !== $linked) {
            $this->refreshIdentity($linked, $identity);
            $this->authIdentityRepository->save($linked);

            return [$linked->getUser(), false];
        }

        $email = $identity->trustedEmail();

        if (null !== $email && null !== $this->userRepository->findOneByEmail($email)) {
            $this->tokenVault->discard($identity);

            throw new OAuthFlowException(OAuthFlowException::ACCOUNT_EXISTS);
        }

        // An email the provider doesn't vouch for is never made the account's email: that would
        // reserve an address for someone who may not own it.
        $user = new User();
        $user->setEmail($email);

        if (null !== $email) {
            $user->markEmailVerified();
        }

        if (null === $registrationTicket) {
            $this->tokenVault->discard($identity);

            throw new OAuthFlowException(OAuthFlowException::INVITATION_REQUIRED);
        }

        // Persisted along with the user (User::$authIdentities cascades).
        $this->newIdentity($user, $identity);

        // Not wrapInTransaction(): it would close the entity manager on a refusal, which the
        // controller then logs. The gate refuses before anything is persisted.
        try {
            $this->entityManager->getConnection()->transactional(function () use ($registrationTicket, $user, $identity): void {
                $this->registrationGate->redeem($registrationTicket, $user, $identity->provider->value);
                $this->userRepository->save($user);
            });
        } catch (RegistrationRefusedException $refusal) {
            $this->tokenVault->discard($identity);

            throw new OAuthFlowException($refusal->reason, $refusal);
        }

        return [$user, true];
    }

    /**
     * Links the identity to $user, who started this flow while signed in.
     *
     * @return bool false if it was already linked to this user (nothing to do)
     *
     * @throws OAuthFlowException IDENTITY_IN_USE or PROVIDER_ALREADY_LINKED
     */
    /**
     * Keeps a token with more scopes for the user's linked account: only when the provider
     * account is the linked one (else the fresh token is revoked and the grant refused). The old
     * token is revoked; the granted scopes are noted in the identity's metadata.
     *
     * @param list<string> $scopes the scopes asked (Lichess returns no list of granted scopes)
     *
     * @throws OAuthFlowException not_linked, identity_mismatch
     */
    public function grant(User $user, ExternalIdentity $identity, array $scopes): void
    {
        $linked = null;
        foreach ($user->getAuthIdentities() as $authIdentity) {
            if ($authIdentity->getProvider() === $identity->provider) {
                $linked = $authIdentity;
            }
        }
        if (null === $linked) {
            $this->tokenVault->discard($identity);

            throw new OAuthFlowException(OAuthFlowException::NOT_LINKED);
        }
        if ($linked->getProviderUserId() !== $identity->providerUserId) {
            $this->tokenVault->discard($identity);

            throw new OAuthFlowException(OAuthFlowException::IDENTITY_MISMATCH);
        }

        $this->refreshIdentity($linked, $identity);
        $linked->setMetadata(['scopes' => array_values(array_unique($scopes))] + $linked->getMetadata());
        $this->authIdentityRepository->save($linked);
        $this->auditLogger->log(AuditEventType::OauthScopesGranted, $user, ['provider' => $identity->provider->value, 'scopes' => $scopes]);
    }

    public function link(User $user, ExternalIdentity $identity): bool
    {
        $existing = $this->authIdentityRepository->findOneByProviderAndUserId($identity->provider, $identity->providerUserId);

        if (null !== $existing) {
            if (!$existing->getUser()->getId()->equals($user->getId())) {
                $this->tokenVault->discard($identity);

                throw new OAuthFlowException(OAuthFlowException::IDENTITY_IN_USE);
            }

            $this->refreshIdentity($existing, $identity);
            $this->authIdentityRepository->save($existing);

            return false;
        }

        foreach ($user->getAuthIdentities() as $authIdentity) {
            if ($authIdentity->getProvider() === $identity->provider) {
                $this->tokenVault->discard($identity);

                throw new OAuthFlowException(OAuthFlowException::PROVIDER_ALREADY_LINKED);
            }
        }

        $this->authIdentityRepository->save($this->newIdentity($user, $identity));
        $this->auditLogger->log(AuditEventType::AccountLinked, $user, ['provider' => $identity->provider->value]);
        $this->notifyLinked($user, $identity);

        return true;
    }

    private function newIdentity(User $user, ExternalIdentity $identity): AuthIdentity
    {
        $authIdentity = new AuthIdentity($user, $identity->provider, $identity->providerUserId);
        $this->refreshIdentity($authIdentity, $identity);

        return $authIdentity;
    }

    /**
     * Keeps the provider-side profile data (and kept token) current; never touches the account's
     * own email.
     */
    private function refreshIdentity(AuthIdentity $authIdentity, ExternalIdentity $identity): void
    {
        $authIdentity->setProviderEmail($identity->email);
        $authIdentity->setMetadata($identity->metadata);
        $this->tokenVault->store($authIdentity, $identity->accessToken);
    }

    private function notifyLinked(User $user, ExternalIdentity $identity): void
    {
        $email = $user->getEmail();

        if (null === $email) {
            return;
        }

        $this->mailer->send((new TemplatedEmail())
            ->from(new Address($this->fromAddress, 'Don\'t Stay Rooky'))
            ->to($email)
            ->subject('Un nouveau compte a été lié à votre compte Don\'t Stay Rooky')
            ->htmlTemplate('emails/account_linked.html.twig')
            ->textTemplate('emails/account_linked.txt.twig')
            ->context([
                'provider' => ucfirst($identity->provider->value),
                'providerEmail' => $identity->email,
                'linkedAt' => new \DateTimeImmutable(),
                'profileUrl' => $this->frontendUrl.'/#/profile',
            ]));
    }
}
