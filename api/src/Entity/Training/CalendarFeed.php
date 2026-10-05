<?php

declare(strict_types=1);

namespace App\Entity\Training;

use App\Entity\User;
use App\Repository\Training\CalendarFeedRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The private calendar address of a user (docs/TRAINING.md, calendar): a secret token in the URL
 * of their iCal feed. One per user; regenerating it renews the row, revoking it deletes the row.
 * The token is kept encrypted (the profile shows the address again) and looked up by its sha256.
 */
#[ORM\Entity(repositoryClass: CalendarFeedRepository::class)]
#[ORM\Table(name: 'training_calendar_feed')]
#[ORM\UniqueConstraint(name: 'uniq_training_calendar_feed_user', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_training_calendar_feed_token', columns: ['token_hash'])]
class CalendarFeed
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** sha256 of the token, what a feed request is matched with. */
    #[ORM\Column(length: 64, options: ['fixed' => true, 'charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $tokenHash;

    /** The token, encrypted with CALENDAR_TOKEN_KEY ({@see \App\Security\Crypto\SecretBox}). */
    #[ORM\Column(length: 255, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $encryptedToken;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $tokenHash, string $encryptedToken, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->tokenHash = $tokenHash;
        $this->encryptedToken = $encryptedToken;
        $this->createdAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getEncryptedToken(): string
    {
        return $this->encryptedToken;
    }

    /** A new token: the previous address stops working. */
    public function renew(string $tokenHash, string $encryptedToken, \DateTimeImmutable $now): void
    {
        $this->tokenHash = $tokenHash;
        $this->encryptedToken = $encryptedToken;
        $this->createdAt = $now;
    }

    /** The same token, encrypted with the current key (after a key rotation). */
    public function reencrypt(string $encryptedToken): void
    {
        $this->encryptedToken = $encryptedToken;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
