<?php

declare(strict_types=1);

namespace App\Entity\Catalog;

use App\Puzzle\Selection\Quality;
use App\Repository\Catalog\PuzzleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A puzzle from the Lichess puzzle database (~5M rows, bulk-loaded: see docs/PUZZLE_IMPORT.md).
 *
 * `moves` keeps the raw UCI line: the first move is the opponent's (played automatically), the
 * player answers from the second move on. `themes` is the display copy of the theme keys; filtering
 * by theme goes through {@see ThemeMembership}, not through this JSON column.
 *
 * Two generated columns (VIRTUAL: added without rebuilding the table, only their index is built)
 * serve the opening sets of the tailored session (docs/COACH.md): `opening_family`, the first opening
 * tag (the family, "Italian_Game"), and `solver_color`, the side the player plays ("w", "b": the
 * other side than the one to move in the FEN, whose move the opponent plays first).
 */
#[ORM\Entity(repositoryClass: PuzzleRepository::class)]
#[ORM\Table(name: 'puzzle')]
#[ORM\UniqueConstraint(name: 'uniq_puzzle_lichess_id', columns: ['lichess_id'])]
#[ORM\Index(name: 'idx_puzzle_selection', columns: ['selectable', 'rating', 'random_key'])]
#[ORM\Index(name: 'idx_puzzle_opening', columns: ['opening_family', 'solver_color', 'selectable', 'rating', 'random_key'])]
class Puzzle
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(length: 5, options: ['fixed' => true, 'charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $lichessId;

    #[ORM\Column(length: 92, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $fen;

    #[ORM\Column(length: 255, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $moves;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $rating;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $ratingDeviation;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $popularity;

    #[ORM\Column(options: ['unsigned' => true])]
    private int $nbPlays;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $themes;

    /** @var list<string>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $openingTags;

    #[ORM\Column(length: 255)]
    private string $gameUrl;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dailyDate = null;

    /** Uniform random value in [0, 2^32): picks a random puzzle among equally rated ones by index seek. */
    #[ORM\Column(options: ['unsigned' => true])]
    private int $randomKey;

    /** Passes the quality thresholds ({@see Quality}); only selectable puzzles are served. */
    #[ORM\Column]
    private bool $selectable;

    #[ORM\Column(
        length: 64,
        nullable: true,
        insertable: false,
        updatable: false,
        columnDefinition: "VARCHAR(64) CHARACTER SET ascii COLLATE `ascii_bin` GENERATED ALWAYS AS (CONVERT(JSON_UNQUOTE(JSON_EXTRACT(opening_tags, '$[0]')) USING ascii)) VIRTUAL",
        generated: 'ALWAYS',
    )]
    private ?string $openingFamily = null;

    #[ORM\Column(
        length: 1,
        nullable: true,
        insertable: false,
        updatable: false,
        columnDefinition: "CHAR(1) CHARACTER SET ascii COLLATE `ascii_bin` GENERATED ALWAYS AS (IF(SUBSTRING(fen, LOCATE(' ', fen) + 1, 1) = 'w', 'b', 'w')) VIRTUAL",
        generated: 'ALWAYS',
    )]
    private ?string $solverColor = null;

    /**
     * @param list<string>      $themes
     * @param list<string>|null $openingTags
     */
    public function __construct(
        string $lichessId,
        string $fen,
        string $moves,
        int $rating,
        int $ratingDeviation,
        int $popularity,
        int $nbPlays,
        array $themes,
        string $gameUrl,
        ?array $openingTags = null,
    ) {
        $this->lichessId = $lichessId;
        $this->fen = $fen;
        $this->moves = $moves;
        $this->rating = $rating;
        $this->ratingDeviation = $ratingDeviation;
        $this->popularity = $popularity;
        $this->nbPlays = $nbPlays;
        $this->themes = $themes;
        $this->gameUrl = $gameUrl;
        $this->openingTags = $openingTags;
        $this->randomKey = random_int(0, 0xFFFFFFFF);
        $this->selectable = Quality::isSelectable($popularity, $nbPlays);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLichessId(): string
    {
        return $this->lichessId;
    }

    public function getFen(): string
    {
        return $this->fen;
    }

    public function getMoves(): string
    {
        return $this->moves;
    }

    /**
     * @return list<string> UCI moves, opponent's first
     */
    public function getMoveList(): array
    {
        return explode(' ', $this->moves);
    }

    public function getRating(): int
    {
        return $this->rating;
    }

    public function getRatingDeviation(): int
    {
        return $this->ratingDeviation;
    }

    public function getPopularity(): int
    {
        return $this->popularity;
    }

    public function getNbPlays(): int
    {
        return $this->nbPlays;
    }

    /**
     * @return list<string>
     */
    public function getThemes(): array
    {
        return $this->themes;
    }

    /**
     * @return list<string>|null
     */
    public function getOpeningTags(): ?array
    {
        return $this->openingTags;
    }

    /** The first opening tag (generated by the database; null until the puzzle is reloaded). */
    public function getOpeningFamily(): ?string
    {
        return $this->openingFamily;
    }

    /** "w" or "b": the side the player plays (generated by the database). */
    public function getSolverColor(): ?string
    {
        return $this->solverColor;
    }

    public function getGameUrl(): string
    {
        return $this->gameUrl;
    }

    public function getDailyDate(): ?\DateTimeImmutable
    {
        return $this->dailyDate;
    }

    public function getRandomKey(): int
    {
        return $this->randomKey;
    }

    public function isSelectable(): bool
    {
        return $this->selectable;
    }
}
