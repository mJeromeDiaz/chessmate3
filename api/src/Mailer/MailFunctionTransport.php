<?php

declare(strict_types=1);

namespace App\Mailer;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * Sends through PHP's mail() (DSN `mailfunction://default`): the way an OVH shared host sends
 * emails (docs/DEPLOY_OVH.md), with its hourly quota. Symfony's `native://` transport would run
 * the sendmail binary itself (proc_open), which a shared host may not allow.
 *
 * The message is rendered by Symfony (MIME, encoding, Message-ID); mail() gets its To and Subject
 * apart, every other header (the body's Content-Type included) as additional headers, and the
 * envelope sender as `-f`, so that bounces go back to our address.
 */
final class MailFunctionTransport extends AbstractTransport
{
    /** @var \Closure(string, string, string, string, string): bool */
    private \Closure $mail;

    /**
     * @param (\Closure(string, string, string, string, string): bool)|null $mail mail() itself, unless a test replaces it
     */
    public function __construct(?\Closure $mail = null, ?EventDispatcherInterface $dispatcher = null, ?LoggerInterface $logger = null)
    {
        parent::__construct($dispatcher, $logger);
        $this->mail = $mail ?? static fn (string $to, string $subject, string $body, string $headers, string $params): bool => mail($to, $subject, $body, $headers, $params);
    }

    public function __toString(): string
    {
        return 'mailfunction://default';
    }

    protected function doSend(SentMessage $message): void
    {
        // The message as it leaves (Message-ID added, signed if a listener signs): headers, a
        // blank line, the body. Each header field may span several lines (folded).
        [$head, $body] = explode("\r\n\r\n", $message->getMessage()->toString(), 2) + ['', ''];
        $to = '';
        $subject = '';
        $kept = [];
        foreach (preg_split('/\r\n(?![ \t])/', $head) ?: [] as $field) {
            $name = strtolower(strstr($field, ':', true) ?: '');
            $value = trim(preg_replace('/\r\n[ \t]+/', ' ', substr($field, \strlen($name) + 1)) ?? '');
            // mail() writes To and Subject itself, and refuses line breaks in them. (Bcc stays: it is
            // read by sendmail -t, which PHP's mail() uses, and stripped on delivery.)
            if ('to' === $name) {
                $to = $value;
            } elseif ('subject' === $name) {
                $subject = $value;
            } else {
                $kept[] = $field;
            }
        }

        $sender = $message->getEnvelope()->getSender()->getAddress();
        $params = 1 === preg_match('/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+$/D', $sender) ? '-f'.$sender : '';

        if (!($this->mail)($to, $subject, $body, implode("\r\n", $kept), $params)) {
            throw new TransportException('mail() refused the message (quota of the host reached, or no local mailer).');
        }
    }
}
