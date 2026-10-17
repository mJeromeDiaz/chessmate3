<?php

declare(strict_types=1);

namespace App\Mailer;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * An email in a language (docs/I18N.md, § 5): its subject is the message "<name>.subject" of the
 * "emails" domain (translations/emails.<locale>.yaml), its bodies templates/emails/<name>.html.twig
 * and .txt.twig, rendered in that language even out of the queue (TemplatedEmail::locale()).
 * The caller sets the sender and the recipient.
 */
final readonly class EmailTemplates
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * @param array<string, mixed>           $context           the templates' variables
     * @param array<string, string|int|null> $subjectParameters
     */
    public function compose(string $name, string $locale, array $context = [], array $subjectParameters = []): TemplatedEmail
    {
        return (new TemplatedEmail())
            ->locale($locale)
            ->subject($this->translator->trans($name.'.subject', $subjectParameters, 'emails', $locale))
            ->htmlTemplate(\sprintf('emails/%s.html.twig', $name))
            ->textTemplate(\sprintf('emails/%s.txt.twig', $name))
            ->context($context);
    }
}
