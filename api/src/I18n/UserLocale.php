<?php

declare(strict_types=1);

namespace App\I18n;

use App\Entity\User;
use App\Enum\Locale;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The language to write to someone in (docs/I18N.md, § 5): the account's choice when it made one,
 * else the language of the request being served (its Accept-Language, read by the framework among
 * the enabled locales), else English. Never French by default: a worker or a cron, with no
 * request, writes in English to an account without a choice.
 */
final readonly class UserLocale
{
    public const DEFAULT = Locale::English;

    public function __construct(private RequestStack $requests)
    {
    }

    /**
     * @param User|null $user null: a visitor (waiting list, sign-up), the request's language
     */
    public function of(?User $user = null): string
    {
        return $user?->getLocale()->value ?? $this->ofRequest();
    }

    /** The language of the request being served, English outside of one. */
    public function ofRequest(): string
    {
        $locale = $this->requests->getCurrentRequest()?->getLocale();

        return null !== $locale && null !== Locale::tryFrom($locale) ? $locale : self::DEFAULT->value;
    }
}
