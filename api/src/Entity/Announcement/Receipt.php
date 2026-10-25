<?php

declare(strict_types=1);

namespace App\Entity\Announcement;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * An announcement and a player (docs/ANNOUNCEMENTS.md): when the player closed its modal, when its
 * email was queued for the player, then sent. Written in SQL by
 * {@see \App\Announcement\Display\CurrentAnnouncements} and {@see \App\Announcement\Email}; the
 * entity maps the schema.
 */
#[ORM\Entity]
#[ORM\Table(name: 'announcement_receipt')]
#[ORM\Index(name: 'idx_announcement_receipt_user', columns: ['user_id'])]
class Receipt
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Announcement::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Announcement $announcement;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** The player closed the modal. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    /** The email was queued for the player. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailQueuedAt = null;

    /** The email was handed to the mailer. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailedAt = null;

    public function __construct(Announcement $announcement, User $user)
    {
        $this->announcement = $announcement;
        $this->user = $user;
    }

    public function getAnnouncement(): Announcement
    {
        return $this->announcement;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function getEmailQueuedAt(): ?\DateTimeImmutable
    {
        return $this->emailQueuedAt;
    }

    public function getEmailedAt(): ?\DateTimeImmutable
    {
        return $this->emailedAt;
    }
}
