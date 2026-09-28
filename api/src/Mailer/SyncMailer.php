<?php

declare(strict_types=1);

namespace App\Mailer;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Sends an email immediately, bypassing the Messenger routing that every other mailer send in this
 * app goes through (see config/packages/messenger.yaml).
 *
 * `TransportInterface` (aliased to the app's single configured transport) is the raw sender that
 * `Symfony\Component\Mailer\Mailer` itself calls once a message comes out of the queue — calling it
 * directly here just skips the queue, not the rest of the pipeline: `AbstractTransport::send()`
 * still dispatches the `MessageEvent` that `Symfony\Component\Mailer\EventListener\MessageListener`
 * uses to render `TemplatedEmail` Twig bodies, so templating keeps working exactly the same.
 *
 * Used only by the 2FA code email: it sits on the critical path of an active login attempt with a
 * 10-minute window, and depending on a Messenger worker being alive for that would be a silent
 * failure point (see docs/SECURITY.md).
 */
final readonly class SyncMailer
{
    public function __construct(private TransportInterface $transport)
    {
    }

    /**
     * @throws TransportExceptionInterface
     */
    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        $this->transport->send($message, $envelope);
    }
}
