<?php

declare(strict_types=1);

namespace App\Entity\Repertoire;

use App\Entity\User;
use App\Enum\Repertoire\ImportStatus;
use App\Repository\Repertoire\ImportRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A PGN import in progress (App\Repertoire\Import\ImportService): the text until it is analysed,
 * then the analysed tree (JSON, App\Repertoire\Import\ImportedTree) until it is applied, and the
 * application request when a worker applies it. Forgotten after a day
 * ({@see \App\Repertoire\Limits::$importTtlHours}), purged lazily.
 */
#[ORM\Entity(repositoryClass: ImportRepository::class)]
#[ORM\Table(name: 'repertoire_import')]
#[ORM\Index(name: 'idx_repertoire_import_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_repertoire_import_expires', columns: ['expires_at'])]
class Import
{
    public const SOURCE_PGN = 'pgn';
    public const SOURCE_STUDY = 'study';
    /** An OpenBook backup (JSON), given like a PGN file. */
    public const SOURCE_OPENBOOK = 'openbook';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 16, enumType: ImportStatus::class)]
    private ImportStatus $status;

    /** pgn (file or paste), study (Lichess) or openbook (backup file). */
    #[ORM\Column(length: 8)]
    private string $source;

    /** File name or study URL, as the user gave it. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $label;

    /** The PGN text, until it is analysed. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $pgn;

    /** The analysed tree (JSON), until it is applied. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $tree = null;

    /** 0 to 100 while a worker analyses or applies it. */
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $progress = 0;

    /** Why it failed: a stable code (App\Repertoire\Import\ImportRejectedException, limit...). */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $error = null;

    /** PGN line of a syntax error. */
    #[ORM\Column(nullable: true)]
    private ?int $errorLine = null;

    /**
     * What the user asked when applying: {repertoireId} or {name, color}, choices, baseVersion.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $request = null;

    /** The repertoire it went into, once done. */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $repertoireId = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    public function __construct(User $user, string $source, ?string $label, string $pgn, \DateTimeImmutable $now, \DateTimeImmutable $expiresAt)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->source = $source;
        $this->label = null === $label ? null : mb_substr($label, 0, 255);
        $this->pgn = $pgn;
        $this->status = ImportStatus::Analyzing;
        $this->createdAt = $now;
        $this->expiresAt = $expiresAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getStatus(): ImportStatus
    {
        return $this->status;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function getPgn(): ?string
    {
        return $this->pgn;
    }

    public function getTree(): ?string
    {
        return $this->tree;
    }

    public function getProgress(): int
    {
        return $this->progress;
    }

    public function setProgress(int $progress): void
    {
        $this->progress = max(0, min(100, $progress));
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getErrorLine(): ?int
    {
        return $this->errorLine;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRequest(): ?array
    {
        return $this->request;
    }

    public function getRepertoireId(): ?Uuid
    {
        return $this->repertoireId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /** Analysed: the text is no longer needed, the tree is. */
    public function analyzed(string $tree): void
    {
        $this->tree = $tree;
        $this->pgn = null;
        $this->status = ImportStatus::Analyzed;
        $this->progress = 100;
    }

    /**
     * @param array<string, mixed> $request
     */
    public function queueApplication(array $request): void
    {
        $this->request = $request;
        $this->status = ImportStatus::Applying;
        $this->progress = 0;
    }

    /** Applied: nothing more to keep but where it went. */
    public function done(Uuid $repertoireId): void
    {
        $this->repertoireId = $repertoireId;
        $this->tree = null;
        $this->pgn = null;
        $this->request = null;
        $this->status = ImportStatus::Done;
        $this->progress = 100;
    }

    public function fail(string $error, ?int $line = null): void
    {
        $this->error = mb_substr($error, 0, 32);
        $this->errorLine = $line;
        $this->pgn = null;
        $this->status = ImportStatus::Failed;
    }

    /** An application refused (a limit, the repertoire changed): back to the preview. */
    public function applicationRefused(string $error): void
    {
        $this->error = mb_substr($error, 0, 32);
        $this->request = null;
        $this->status = ImportStatus::Analyzed;
        $this->progress = 100;
    }
}
