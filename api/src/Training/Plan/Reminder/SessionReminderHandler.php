<?php

declare(strict_types=1);

namespace App\Training\Plan\Reminder;

use App\Enum\Training\Module;
use App\I18n\UserLocale;
use App\Mailer\EmailTemplates;
use App\Notification\Push\PushMessage;
use App\Notification\Push\PushSender;
use App\Repository\Training\PlanRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private EmailTemplates $emails,
        private UserLocale $userLocale,
        private TranslatorInterface $translator,
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
        // Suspended (or frozen) since the reminder was queued: nothing goes out.
        if ($user->isSuspended() || $user->isFrozen()) {
            return;
        }
        $local = $occursAt->setTimezone($user->getDateTimeZone());
        // A cron or a worker: the account's language, English if it chose none (docs/I18N.md, § 5).
        $locale = $this->userLocale->of($user);
        $title = '' !== $plan->getTitle() ? $plan->getTitle() : $this->translator->trans('session_reminder.default_title', [], 'emails', $locale);
        $when = $this->when($local, $now->setTimezone($user->getDateTimeZone()), $locale);
        $url = $this->frontendUrl.'/#/session';

        if (\in_array('email', $plan->getReminderChannels(), true) && null !== $user->getEmail() && $user->isEmailVerified()) {
            $this->mailer->send($this->emails->compose('session_reminder', $locale, [
                'title' => $title,
                'when' => $when,
                'steps' => array_map(fn (array $step): array => [
                    'label' => null === Module::tryFrom($step['module']) ? $step['module'] : $this->translator->trans('module.'.$step['module'], [], 'training', $locale),
                    'minutes' => $step['minutes'],
                ], $plan->getSteps()),
                'totalMinutes' => array_sum(array_column($plan->getSteps(), 'minutes')),
                'url' => $url,
            ], ['%title%' => $title, '%when%' => $when])
                ->from(new Address($this->fromAddress, 'Don\'t Stay Rooky'))
                ->to($user->getEmail()));
        }
        if (\in_array('push', $plan->getReminderChannels(), true)) {
            $this->push->send($user, new PushMessage(
                title: $title,
                body: $this->translator->trans('session_reminder.body', ['%when%' => $when], 'notifications', $locale),
                url: '/#/session',
                tag: 'session-reminder-'.$plan->getId()->toRfc4122(),
                ttlSeconds: max(60, $occursAt->getTimestamp() - $now->getTimestamp()),
            ));
        }
    }

    /**
     * "à 18:30", "demain à 07:00", "le 12/10 à 18:30" (local times), in the language given.
     */
    private function when(\DateTimeImmutable $at, \DateTimeImmutable $now, string $locale): string
    {
        $days = (int) $now->setTime(0, 0)->diff($at->setTime(0, 0))->format('%r%a');
        $key = match ($days) {
            0 => 'today',
            1 => 'tomorrow',
            default => 'later',
        };

        return $this->translator->trans('session_reminder.when.'.$key, ['%date%' => $at->format('d/m'), '%time%' => $at->format('H:i')], 'emails', $locale);
    }
}
