<?php

declare(strict_types=1);

namespace App\Entity\Announcement;

use App\Entity\User;
use App\Enum\Announcement\Status;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A message of the team to the players (docs/ANNOUNCEMENTS.md): a title and a sanitized HTML text
 * in French, in English too when written, shown once to each active account in a modal between
 * its start and its end (both optional), and sent once by email when an admin asks for it (the
 * email waits for the start). Who saw it and who was emailed: {@see Receipt}.
 */
#[ORM\Entity]
#[ORM\Table(name: 'announcement')]
#[ORM\Index(name: 'idx_announcement_status_starts', columns: ['status', 'starts_at'])]
class Announcement
{
    public const TITLE_LENGTH = 120;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: self::TITLE_LENGTH)]
    private string $titleFr;

    /** Sanitized HTML ({@see \App\Announcement\Admin\AnnouncementEditor}). */
    #[ORM\Column(type: 'text')]
    private string $bodyFr;

    /** Null: the English players read the French text. */
    #[ORM\Column(length: self::TITLE_LENGTH, nullable: true)]
    private ?string $titleEn = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $bodyEn = null;

    #[ORM\Column(length: 12, enumType: Status::class)]
    private Status $status = Status::Draft;

    /** Shown from then on; null: as soon as published. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startsAt = null;

    /** Shown until then; null: until archived. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    /** The email asked by an admin: queued from the start on, a few recipients each minute. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailRequestedAt = null;

    /** Every recipient queued (or the announcement ended or archived first). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailFinishedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $author;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $titleFr, string $bodyFr, ?User $author, \DateTimeImmutable $createdAt)
    {
        $this->id = Uuid::v7();
        $this->titleFr = $titleFr;
        $this->bodyFr = $bodyFr;
        $this->author = $author;
        $this->createdAt = $createdAt;
        $this->updatedAt = $createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTitleFr(): string
    {
        return $this->titleFr;
    }

    public function getBodyFr(): string
    {
        return $this->bodyFr;
    }

    public function getTitleEn(): ?string
    {
        return $this->titleEn;
    }

    public function getBodyEn(): ?string
    {
        return $this->bodyEn;
    }

    /**
     * The title and the text in a language: English when written, else French.
     *
     * @return array{title: string, body: string}
     */
    public function textsIn(string $locale): array
    {
        return 'en' === $locale && null !== $this->titleEn && null !== $this->bodyEn
            ? ['title' => $this->titleEn, 'body' => $this->bodyEn]
            : ['title' => $this->titleFr, 'body' => $this->bodyFr];
    }

    /**
     * The texts, the English ones both null or both set.
     */
    public function write(string $titleFr, string $bodyFr, ?string $titleEn, ?string $bodyEn, \DateTimeImmutable $now): static
    {
        $this->titleFr = $titleFr;
        $this->bodyFr = $bodyFr;
        $this->titleEn = $titleEn;
        $this->bodyEn = $bodyEn;
        $this->updatedAt = $now;

        return $this;
    }

    public function getStatus(): Status
    {
        return $this->status;
    }

    public function getStartsAt(): ?\DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getEndsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function schedule(?\DateTimeImmutable $startsAt, ?\DateTimeImmutable $endsAt): static
    {
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;

        return $this;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function publish(\DateTimeImmutable $now): static
    {
        $this->status = Status::Published;
        $this->publishedAt ??= $now;
        $this->updatedAt = $now;

        return $this;
    }

    public function archive(\DateTimeImmutable $now): static
    {
        $this->status = Status::Archived;
        $this->updatedAt = $now;

        return $this;
    }

    /** Published, started and not ended: shown to the players. */
    public function isLive(\DateTimeImmutable $now): bool
    {
        return Status::Published === $this->status
            && (null === $this->startsAt || $this->startsAt <= $now)
            && (null === $this->endsAt || $this->endsAt > $now);
    }

    public function hasEnded(\DateTimeImmutable $now): bool
    {
        return null !== $this->endsAt && $this->endsAt <= $now;
    }

    public function getEmailRequestedAt(): ?\DateTimeImmutable
    {
        return $this->emailRequestedAt;
    }

    public function requestEmail(\DateTimeImmutable $now): static
    {
        $this->emailRequestedAt = $now;

        return $this;
    }

    public function getEmailFinishedAt(): ?\DateTimeImmutable
    {
        return $this->emailFinishedAt;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
