<?php

declare(strict_types=1);

namespace App\Security\Password\Message;

use App\Entity\User;
use App\Enum\AuditEventType;
use App\Repository\UserRepository;
use App\Security\Audit\AuditLogger;
use App\Security\UserAgent\UserAgentSummarizer;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use SymfonyCasts\Bundle\ResetPassword\Exception\TooManyPasswordRequestsException;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * Sends the reset link, out of the HTTP request (see config/packages/messenger.yaml). Every branch
 * that doesn't send one — unknown email, throttled — is silent: the requester already got the same
 * generic answer regardless.
 */
#[AsMessageHandler]
final readonly class PasswordResetRequestedHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private ResetPasswordHelperInterface $resetPasswordHelper,
        private MailerInterface $mailer,
        private AuditLogger $auditLogger,
        private UserAgentSummarizer $userAgentSummarizer,
        #[Autowire('%env(MAILER_FROM_ADDRESS)%')]
        private string $fromAddress,
        #[Autowire('%env(FRONTEND_URL)%')]
        private string $frontendUrl,
    ) {
    }

    public function __invoke(PasswordResetRequested $message): void
    {
        $user = $this->userRepository->findOneByEmail($message->email);

        if (null === $user) {
            return;
        }

        if (!$user->hasPassword()) {
            // OAuth-only account: a password can only be added from the profile, never through an
            // emailed link — so explain instead of sending one.
            $this->send($message, 'emails/reset_password_no_password', ['frontendUrl' => $this->frontendUrl]);
            $this->audit($user, $message, ['outcome' => 'no_password']);

            return;
        }

        try {
            $token = $this->resetPasswordHelper->generateResetToken($user);
        } catch (TooManyPasswordRequestsException) {
            $this->audit($user, $message, ['outcome' => 'throttled']);

            return;
        }

        // Hash-mode SPA route: the token sits in the URL fragment, which browsers never send to any
        // server (no access log, no Referer leak).
        $this->send($message, 'emails/reset_password', [
            'resetUrl' => $this->frontendUrl.'/#/reset-password?token='.rawurlencode($token->getToken()),
            'expiresInMinutes' => intdiv($this->resetPasswordHelper->getTokenLifetime(), 60),
        ]);
        $this->audit($user, $message, ['outcome' => 'link_sent']);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function send(PasswordResetRequested $message, string $template, array $context): void
    {
        $this->mailer->send((new TemplatedEmail())
            ->from(new Address($this->fromAddress, 'ChessMate'))
            ->to($message->email)
            ->subject('Réinitialisation de votre mot de passe ChessMate')
            ->htmlTemplate($template.'.html.twig')
            ->textTemplate($template.'.txt.twig')
            ->context($context + [
                'requestedAt' => $message->requestedAt,
                'device' => $this->userAgentSummarizer->summarize($message->userAgent),
                'ip' => $message->ip ?? 'unknown',
            ]));
    }

    /**
     * @param array<string, string> $metadata
     */
    private function audit(User $user, PasswordResetRequested $message, array $metadata): void
    {
        $this->auditLogger->log(AuditEventType::PasswordResetRequested, $user, $metadata, $message->ip, $message->userAgent);
    }
}
