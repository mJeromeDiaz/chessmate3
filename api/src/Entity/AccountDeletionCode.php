<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AccountDeletionCodeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The 6-digit code sent by email to confirm an account deletion (docs/AUTH.md), one per user at
 * most: asking again replaces it. Only its HMAC is stored ({@see \App\Security\Account\AccountDeletion}),
 * as for the 2FA code; 10 minutes and 5 attempts.
 */
#[ORM\Entity(repositoryClass: AccountDeletionCodeRepository::class)]
#[ORM\Table(name: 'account_deletion_code')]
#[ORM\UniqueConstraint(name: 'uniq_account_deletion_code_user', fields: ['user'])]
class AccountDeletionCode
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(length: 64, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
        private string $codeHash,
        #[ORM\Column]
        private \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = $createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCodeHash(): string
    {
        return $this->codeHash;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
