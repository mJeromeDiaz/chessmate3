<?php

declare(strict_types=1);

namespace App\Announcement\Email;

use App\Entity\Announcement\Announcement;
use App\I18n\UserLocale;
use App\Mailer\EmailTemplates;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Sends an announcement's email to one player (docs/ANNOUNCEMENTS.md, § 4), in the account's
 * language (English when written, else French), with a one-click "stop these emails" link
 * (List-Unsubscribe, RFC 8058) and the same link in its footer. Checked again when sending: nothing
 * once the announcement is archived or ended, the account suspended, being deleted, unverified or
 * opted out. At-least-once delivery: the receipt is claimed first, so a message handled twice
 * sends one email.
 */
#[AsMessageHandler]
final readonly class AnnouncementEmailHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $users,
        private Connection $connection,
        private MailerInterface $mailer,
        private EmailTemplates $emails,
        private UserLocale $userLocale,
        private UnsubscribeToken $tokens,
        private UrlGeneratorInterface $urls,
        private ClockInterface $clock,
        #[Autowire('%env(MAILER_FROM_ADDRESS)%')]
        private string $fromAddress,
        #[Autowire('%env(FRONTEND_URL)%')]
        private string $frontendUrl,
    ) {
    }

    public function __invoke(AnnouncementEmailDue $message): void
    {
        if (!Uuid::isValid($message->announcementId) || !Uuid::isValid($message->userId)) {
            return;
        }
        $now = $this->clock->now();
        $announcement = $this->entityManager->find(Announcement::class, Uuid::fromString($message->announcementId));
        $user = $this->users->find(Uuid::fromString($message->userId));
        if (null === $announcement || !$announcement->isLive($now) || null === $user || $user->isSuspended() || $user->isFrozen()
            || !$user->isEmailVerified() || !$user->hasAnnouncementEmails() || null === ($address = $user->getEmail())) {
            return;
        }
        $claim = [$now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $announcement->getId()->toBinary(), $user->getId()->toBinary()];
        $types = [ParameterType::STRING, ParameterType::BINARY, ParameterType::BINARY];
        if (0 === $this->connection->executeStatement(
            'UPDATE announcement_receipt SET emailed_at = ? WHERE announcement_id = ? AND user_id = ? AND emailed_at IS NULL',
            $claim,
            $types,
        )) {
            return;
        }

        $locale = $this->userLocale->of($user);
        ['title' => $title, 'body' => $body] = $announcement->textsIn($locale);
        $token = $this->tokens->of($user->getId());
        $userId = $user->getId()->toRfc4122();
        $oneClick = $this->urls->generate('app_announcement_unsubscribe', ['u' => $userId, 't' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
        $email = $this->emails->compose('announcement', $locale, [
            'title' => $title,
            'body' => $body,
            'text' => PlainText::of($body),
            'url' => $this->frontendUrl.'/#/',
            'unsubscribeUrl' => $this->frontendUrl.'/#/annonces/desinscription?'.http_build_query(['u' => $userId, 't' => $token]),
        ], ['%title%' => $title])
            ->from(new Address($this->fromAddress, 'Don\'t Stay Rooky'))
            ->to($address);
        $email->getHeaders()
            ->addTextHeader('List-Unsubscribe', '<'.$oneClick.'>')
            ->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        try {
            $this->mailer->send($email);
        } catch (\Throwable $error) {
            // Not handed over: free the claim for the retry.
            $this->connection->executeStatement(
                'UPDATE announcement_receipt SET emailed_at = NULL WHERE announcement_id = ? AND user_id = ?',
                [$claim[1], $claim[2]],
                [ParameterType::BINARY, ParameterType::BINARY],
            );
            throw $error;
        }
    }
}
