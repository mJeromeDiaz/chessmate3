<?php

declare(strict_types=1);

namespace App\Gamification\Streak;

use App\Activity\Log\LocalDate;
use App\Notification\Push\PushMessage;
use App\Notification\Push\PushSender;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Uid\Uuid;

/**
 * Sends the "streak in danger" reminder (docs/NOTIFICATIONS.md, § 5): a Web Push to each subscribed
 * browser, an email too when chosen (verified address only). Checked again when sending: nothing
 * once the day is over, once the user played today, if the streak already broke, if the reminder
 * was turned off or the account suspended or frozen.
 */
#[AsMessageHandler]
final readonly class StreakReminderHandler
{
    public function __construct(
        private UserRepository $users,
        private StreakReminders $reminders,
        private Connection $connection,
        private MailerInterface $mailer,
        private PushSender $push,
        private ClockInterface $clock,
        #[Autowire('%env(MAILER_FROM_ADDRESS)%')]
        private string $fromAddress,
        #[Autowire('%env(FRONTEND_URL)%')]
        private string $frontendUrl,
    ) {
    }

    public function __invoke(StreakReminderDue $message): void
    {
        if (!Uuid::isValid($message->userId)) {
            return;
        }
        $user = $this->users->find(Uuid::fromString($message->userId));
        if (null === $user || $user->isSuspended() || $user->isFrozen()) {
            return;
        }
        $settings = $this->reminders->settings($user);
        $timezone = $user->getDateTimeZone();
        $now = $this->clock->now();
        $today = LocalDate::of($now, $timezone);
        if (!$settings['enabled'] || LocalDate::of(new \DateTimeImmutable($message->dueAt), $timezone)->format('Y-m-d') !== $today->format('Y-m-d')) {
            return;
        }
        // The last active day, announced by its first exercise in that exercise's transaction:
        // yesterday, or the streak is either safe (today) or already broken.
        $last = $this->connection->fetchAssociative(
            'SELECT local_date, streak FROM gamification_streak_notice WHERE user_id = ?',
            [$user->getId()->toBinary()],
            [ParameterType::BINARY],
        );
        if (false === $last || $last['local_date'] !== $today->modify('-1 day')->format('Y-m-d') || !is_numeric($last['streak'])) {
            return;
        }
        $streak = (int) $last['streak'];
        $days = \sprintf('%d jour%s', $streak, $streak > 1 ? 's' : '');
        $midnight = (new \DateTimeImmutable($today->format('Y-m-d').' 00:00:00', $timezone))->modify('+1 day');

        $this->push->send($user, new PushMessage(
            title: 'Ta série est en danger 🔥',
            body: \sprintf('Ta série de %s s’arrête à minuit. Un exercice suffit pour la garder !', $days),
            url: '/#/',
            tag: 'streak-reminder',
            ttlSeconds: max(60, $midnight->getTimestamp() - $now->getTimestamp()),
        ));
        if ($settings['email'] && null !== $user->getEmail() && $user->isEmailVerified()) {
            $this->mailer->send((new TemplatedEmail())
                ->from(new Address($this->fromAddress, 'Don\'t Stay Rooky'))
                ->to($user->getEmail())
                ->subject(\sprintf('Ta série de %s s’arrête à minuit', $days))
                ->htmlTemplate('emails/streak_reminder.html.twig')
                ->textTemplate('emails/streak_reminder.txt.twig')
                ->context(['days' => $days, 'url' => $this->frontendUrl.'/#/', 'profileUrl' => $this->frontendUrl.'/#/profile']));
        }
    }
}
