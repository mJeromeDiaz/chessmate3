<?php

declare(strict_types=1);

namespace App\Entity\Repertoire;

use App\Entity\User;
use App\Enum\Repertoire\AutoBuildStatus;
use App\Repository\Repertoire\AutoBuildRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A repertoire being built from the user's Lichess games (App\Repertoire\Auto\AutoBuildService):
 * the settings, the download cursor, the games read so far (compact JSON,
 * App\Repertoire\Auto\GameRecord), then the user's selection until the repertoires are created.
 * One per user (a new one replaces it), forgotten after a day
 * ({@see \App\Repertoire\Limits::$autoTtlHours}), purged lazily.
 */
#[ORM\Entity(repositoryClass: AutoBuildRepository::class)]
#[ORM\Table(name: 'repertoire_auto_build')]
#[ORM\Index(name: 'idx_repertoire_auto_build_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_repertoire_auto_build_expires', columns: ['expires_at'])]
class AutoBuild
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 16, enumType: AutoBuildStatus::class)]
    private AutoBuildStatus $status = AutoBuildStatus::Fetching;

    /** The linked Lichess account whose games are read (its id, lowercase). */
    #[ORM\Column(length: 30)]
    private string $username;

    /** Games wanted (50, 100, 200 or 500). */
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $maxGames;

    /**
     * Lichess speeds read: bullet, blitz, rapid, classical.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $perfs;

    /** Download cursor: the next batch asks for games created before this instant (ms), null at first. */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $untilMs = null;

    /** Games Lichess sent, kept or not (another variant, aborted...). */
    #[ORM\Column(options: ['unsigned' => true, 'default' => 0])]
    private int $scanned = 0;

    /** Games kept. */
    #[ORM\Column(options: ['unsigned' => true, 'default' => 0])]
    private int $gameCount = 0;

    /** The games kept (JSON list of App\Repertoire\Auto\GameRecord arrays), newest first. */
    #[ORM\Column(type: Types::TEXT)]
    private string $games = '[]';

    /** Why the download stopped early (lichess_user_not_found), null otherwise. */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $error = null;

    /**
     * The user's choices (App\Repertoire\Auto\Selection): openings, moves, lines removed.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $selection = null;

    /**
     * Cloud evaluations of the line ends: normalized FEN => {cp, mate} from White's point of view,
     * or null when Lichess has none.
     *
     * @var array<string, array{cp: int|null, mate: int|null}|null>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $evaluations = [];

    /**
     * The repertoires created, once applied.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $repertoireIds = [];

    /**
     * The exploration of the opponents' replies (App\Repertoire\Auto\Exploration::toArray()): the
     * rule frozen at its start, the explorer's answers by normalized FEN, the calls made and allowed.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $exploration = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    /**
     * @param list<string> $perfs
     */
    public function __construct(User $user, string $username, int $maxGames, array $perfs, \DateTimeImmutable $now, \DateTimeImmutable $expiresAt)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->username = $username;
        $this->maxGames = $maxGames;
        $this->perfs = $perfs;
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

    public function getStatus(): AutoBuildStatus
    {
        return $this->status;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getMaxGames(): int
    {
        return $this->maxGames;
    }

    /**
     * @return list<string>
     */
    public function getPerfs(): array
    {
        return $this->perfs;
    }

    public function getUntilMs(): ?int
    {
        return $this->untilMs;
    }

    public function getScanned(): int
    {
        return $this->scanned;
    }

    public function getGameCount(): int
    {
        return $this->gameCount;
    }

    public function getGames(): string
    {
        return $this->games;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * A batch read: the games kept (the whole list, JSON), how many Lichess sent, where the next
     * batch starts.
     */
    public function batchRead(string $games, int $gameCount, int $scanned, ?int $untilMs): void
    {
        $this->games = $games;
        $this->gameCount = $gameCount;
        $this->scanned += $scanned;
        $this->untilMs = $untilMs;
    }

    /** Every game wanted is in (or Lichess has no more). */
    public function ready(?string $error = null): void
    {
        $this->status = AutoBuildStatus::Ready;
        $this->error = $error;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSelection(): ?array
    {
        return $this->selection;
    }

    /**
     * @param array<string, mixed> $selection
     */
    public function select(array $selection): void
    {
        $this->selection = $selection;
    }

    /**
     * @return array<string, array{cp: int|null, mate: int|null}|null>
     */
    public function getEvaluations(): array
    {
        return $this->evaluations;
    }

    /**
     * @param array<string, array{cp: int|null, mate: int|null}|null> $evaluations
     */
    public function addEvaluations(array $evaluations): void
    {
        $this->evaluations = array_merge($this->evaluations, $evaluations);
    }

    /**
     * @return list<string>
     */
    public function getRepertoireIds(): array
    {
        return $this->repertoireIds;
    }

    /**
     * Applied: the games stay until it expires (the tree can still be reviewed), nothing more
     * can be created from it.
     *
     * @param list<string> $repertoireIds
     */
    public function applied(array $repertoireIds): void
    {
        $this->repertoireIds = $repertoireIds;
        $this->status = AutoBuildStatus::Applied;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getExploration(): ?array
    {
        return $this->exploration;
    }

    /**
     * @param array<string, mixed> $exploration
     */
    public function explored(array $exploration): void
    {
        $this->exploration = $exploration;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
