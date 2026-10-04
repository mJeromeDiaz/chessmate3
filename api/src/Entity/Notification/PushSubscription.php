<?php

declare(strict_types=1);

namespace App\Entity\Notification;

use App\Entity\User;
use App\Repository\Notification\PushSubscriptionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A browser of the user that accepted Web Push notifications (docs/NOTIFICATIONS.md): the push
 * service's endpoint and the keys the payload is encrypted with. One row per endpoint (unique
 * hash): the same browser signing into another account moves to that account.
 */
#[ORM\Entity(repositoryClass: PushSubscriptionRepository::class)]
#[ORM\Table(name: 'notification_push_subscription')]
#[ORM\UniqueConstraint(name: 'uniq_notification_push_subscription_endpoint', columns: ['endpoint_hash'])]
#[ORM\Index(name: 'idx_notification_push_subscription_user', columns: ['user_id'])]
class PushSubscription
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 2048, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $endpoint;

    /** sha256 of the endpoint, under the unique index (the endpoint is too long to index). */
    #[ORM\Column(length: 64, options: ['fixed' => true, 'charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $endpointHash;

    /** The browser's P-256 public key (p256dh), base64url. */
    #[ORM\Column(length: 128, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $publicKey;

    /** The browser's authentication secret, base64url. */
    #[ORM\Column(length: 64, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $authToken;

    #[ORM\Column(length: 160)]
    private string $userAgent;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSuccessAt = null;

    public function __construct(User $user, string $endpoint, string $publicKey, string $authToken, string $userAgent, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->endpoint = $endpoint;
        $this->endpointHash = self::hash($endpoint);
        $this->createdAt = $now;
        $this->renew($user, $publicKey, $authToken, $userAgent);
    }

    public static function hash(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    /**
     * The browser subscribed again (new keys) or signed into another account.
     */
    public function renew(User $user, string $publicKey, string $authToken, string $userAgent): void
    {
        $this->user = $user;
        $this->publicKey = $publicKey;
        $this->authToken = $authToken;
        $this->userAgent = mb_substr($userAgent, 0, 160);
    }

    public function delivered(\DateTimeImmutable $at): void
    {
        $this->lastSuccessAt = $at;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    public function getAuthToken(): string
    {
        return $this->authToken;
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastSuccessAt(): ?\DateTimeImmutable
    {
        return $this->lastSuccessAt;
    }
}
