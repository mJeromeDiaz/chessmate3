<?php

declare(strict_types=1);

namespace App\Training\Plan\Reminder;

use App\Enum\Training\Module;
use App\Notification\Push\PushMessage;
use App\Notification\Push\PushSender;
use App\Repository\Training\PlanRepository;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Uid\Uuid;

/**
 * Sends the reminder of a saved session on the channels the user chose (docs/TRAINING.md): an
 * email to a verified address, a Web Push to each subscribed browser. A plan deleted or whose
 * reminder was turned off meanwhile gets nothing; nothing is sent once the session has begun.
 */
#[AsMessageHandler]
final readonly class SessionReminderHandler
{
    public function __construct(
        private PlanRepository $plans,
        private MailerInterface $mailer,
        private PushSender $push,
        private ClockInterface $clock,
        #[Autowire('%env(MAILER_FROM_ADDRESS)%')]
        private string $fromAddress,
        #[Autowire('%env(FRONTEND_URL)%')]
        private string $frontendUrl,
    ) {
    }

    public function __invoke(SessionReminderDue $message): void
    {
        if (!Uuid::isValid($message->planId)) {
            return;
        }
        $plan = $this->plans->find(Uuid::fromString($message->planId));
        $occursAt = new \DateTimeImmutable($message->occursAt);
        $now = $this->clock->now();
        if (null === $plan || !$plan->isReminderEnabled() || $occursAt <= $now) {
            return;
        }
        $user = $plan->getUser();
        $local = $occursAt->setTimezone($user->getDateTimeZone());
        $title = '' !== $plan->getTitle() ? $plan->getTitle() : 'Ta session';
        $when = self::when($local, $now->setTimezone($user->getDateTimeZone()));
        $url = $this->frontendUrl.'/#/session';

        if (\in_array('email', $plan->getReminderChannels(), true) && null !== $user->getEmail() && $user->isEmailVerified()) {
            $this->mailer->send((new TemplatedEmail())
                ->from(new Address($this->fromAddress, 'ChessMate'))
                ->to($user->getEmail())
                ->subject(sprintf('Rappel : %s %s', $title, $when))
                ->htmlTemplate('emails/session_reminder.html.twig')
                ->textTemplate('emails/session_reminder.txt.twig')
                ->context([
                    'title' => $title,
                    'when' => $when,
                    'steps' => array_map(static fn (array $step): array => [
                        'label' => Module::tryFrom($step['module'])?->label() ?? $step['module'],
                        'minutes' => $step['minutes'],
                    ], $plan->getSteps()),
                    'totalMinutes' => array_sum(array_column($plan->getSteps(), 'minutes')),
                    'url' => $url,
                ]));
        }
        if (\in_array('push', $plan->getReminderChannels(), true)) {
            $this->push->send($user, new PushMessage(
                title: $title,
                body: sprintf('Ta session commence %s.', $when),
                url: '/#/session',
                tag: 'session-reminder-'.$plan->getId()->toRfc4122(),
                ttlSeconds: max(60, $occursAt->getTimestamp() - $now->getTimestamp()),
            ));
        }
    }

    /**
     * "à 18:30", "demain à 07:00", "le 12/10 à 18:30" (local times).
     */
    private static function when(\DateTimeImmutable $at, \DateTimeImmutable $now): string
    {
        $days = (int) $now->setTime(0, 0)->diff($at->setTime(0, 0))->format('%r%a');

        return match ($days) {
            0 => 'à '.$at->format('H:i'),
            1 => 'demain à '.$at->format('H:i'),
            default => sprintf('le %s à %s', $at->format('d/m'), $at->format('H:i')),
        };
    }
}
