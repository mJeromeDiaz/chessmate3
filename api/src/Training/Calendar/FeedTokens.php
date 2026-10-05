<?php

declare(strict_types=1);

namespace App\Training\Calendar;

use App\Entity\Training\CalendarFeed;
use App\Entity\User;
use App\Repository\Training\CalendarFeedRepository;
use App\Security\Crypto\SecretBox;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The secret token in a user's calendar address (docs/TRAINING.md, calendar): 32 random bytes,
 * base64url. Kept encrypted to show the address again, found by its sha256; regenerating it
 * invalidates the previous address, revoking it closes the feed.
 */
final readonly class FeedTokens
{
    /** What a token looks like in a URL (route requirement). */
    public const PATTERN = '[A-Za-z0-9_-]{43}';

    public function __construct(
        private CalendarFeedRepository $feeds,
        private EntityManagerInterface $entityManager,
        #[Autowire(service: 'app.calendar.secret_box')]
        private SecretBox $secretBox,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The user's current token, null if they have no calendar address.
     */
    public function current(User $user): ?string
    {
        $feed = $this->feeds->findForUser($user);
        if (null === $feed) {
            return null;
        }
        $token = $this->secretBox->decrypt($feed->getEncryptedToken());
        if ($this->secretBox->needsReencryption($feed->getEncryptedToken())) {
            $feed->reencrypt($this->secretBox->encrypt($token));
            $this->entityManager->flush();
        }

        return $token;
    }

    /**
     * A new token (the first one, or one replacing the previous address).
     */
    public function regenerate(User $user): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hash = self::hash($token);
        $encrypted = $this->secretBox->encrypt($token);
        $now = $this->clock->now();

        $feed = $this->feeds->findForUser($user);
        if (null === $feed) {
            $this->entityManager->persist(new CalendarFeed($user, $hash, $encrypted, $now));
        } else {
            $feed->renew($hash, $encrypted, $now);
        }
        $this->entityManager->flush();

        return $token;
    }

    public function revoke(User $user): void
    {
        $feed = $this->feeds->findForUser($user);
        if (null !== $feed) {
            $this->entityManager->remove($feed);
            $this->entityManager->flush();
        }
    }

    /**
     * The owner of a token, null if no address uses it.
     */
    public function owner(string $token): ?User
    {
        return $this->feeds->findByTokenHash(self::hash($token))?->getUser();
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
