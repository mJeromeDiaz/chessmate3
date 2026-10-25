<?php

declare(strict_types=1);

namespace App\ApiResource\Training;

use App\Blindfold\Recall\Placement;
use App\Calculation\CalculationRules;
use App\Blindfold\Recall\RecallRules;
use App\Coordinates\Series\CoordinateRules;
use App\Entity\Puzzle\Attempt;
use App\Enum\Evaluation\Plan;
use App\Notation\NotationRules;
use App\Endgame\EndgameRules;
use App\Vision\VisionRules;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The client reports what happened on an item, never whether it succeeded; except the engine's
 * verdict of chapter 3 of the calculation path, judged in the browser (docs/ENGINE.md, § 3), and of
 * an endgame the tablebase could not judge (docs/ENDGAMES.md, § 5).
 */
final class SubmitItemInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 64)]
    public string $itemId = '';

    /**
     * @var list<string> UCI moves tried by the player, in order, wrong ones included; the endgames
     *                   module's whole game, both sides (longer: puzzles check their own limit)
     */
    #[Assert\Count(max: EndgameRules::MAX_PLIES)]
    #[Assert\All([new Assert\Type('string'), new Assert\Regex('/^[a-h][1-8][a-h][1-8][qrbn]?$/')])]
    public array $moves = [];

    #[Assert\Range(min: 0, max: Attempt::MAX_HINT_LEVEL)]
    public int $hintLevel = 0;

    public bool $solutionShown = false;

    /** The puzzle streak: the puzzle skipped with a pass of the store, before any move (docs/PUZZLE_STREAK.md). */
    public bool $pass = false;

    /** Endgames: the net switched on during the position, where it was only offered (docs/ENDGAMES.md, § 8). */
    public bool $net = false;

    /** Think time measured by the client, ms: lowers the server's own measure, never raises it. */
    #[Assert\Range(min: 0, max: 3_600_000)]
    public ?int $thinkMs = null;

    /** @var list<array{index: int, square: string, ms: int}> coordinates series: squares clicked or named, or light / dark (the module checks which its mode takes), judged by the server (docs/COORDINATES.md) */
    #[Assert\Count(max: CoordinateRules::MAX_ANSWERS_PER_SUBMISSION)]
    #[Assert\All([new Assert\Collection(fields: [
        'index' => [new Assert\NotNull(), new Assert\Type('int'), new Assert\Range(min: 0, max: CoordinateRules::MAX_SQUARES - 1)],
        'square' => [new Assert\NotNull(), new Assert\Type('string'), new Assert\Regex('/^([a-h][1-8]|light|dark)$/')],
        'ms' => [new Assert\NotNull(), new Assert\Type('int'), new Assert\Range(min: 0, max: 3_600_000)],
    ])])]
    public array $answers = [];

    /** @var array{guess?: int|null, plan?: string|null}|null position evaluation: the category chosen (-2 to 2, null: none in time) and the plan, judged by the server (docs/EVALUATION.md) */
    #[Assert\Collection(fields: [
        'guess' => new Assert\Optional([new Assert\Type('int'), new Assert\Range(min: -2, max: 2)]),
        'plan' => new Assert\Optional([new Assert\Type('string'), new Assert\Choice(callback: [Plan::class, 'values'])]),
    ])]
    public ?array $evaluation = null;

    /** @var list<array{square: string, piece: string}> position recall: the pieces placed, judged by the server (docs/BLINDFOLD_RECALL.md) */
    #[Assert\Count(max: RecallRules::MAX_PLACED)]
    #[Assert\All([new Assert\Collection(fields: [
        'square' => [new Assert\NotNull(), new Assert\Type('string'), new Assert\Regex(Placement::SQUARE_PATTERN)],
        'piece' => [new Assert\NotNull(), new Assert\Type('string'), new Assert\Regex(Placement::PIECE_PATTERN)],
    ])])]
    public array $placement = [];

    /**
     * @var list<string> notation: what was written, SAN with English letters, move numbers allowed, one or more half-moves
     *                   per string (the module splits them and checks their count, docs/NOTATION.md)
     */
    #[Assert\Count(max: NotationRules::MAX_WRITTEN)]
    #[Assert\All([new Assert\Type('string'), new Assert\Length(max: NotationRules::MAX_WRITTEN_LENGTH)])]
    public array $written = [];

    /** @var list<string> vision: the squares clicked on the empty board (docs/VISION.md) */
    #[Assert\Count(max: VisionRules::MAX_SQUARES)]
    #[Assert\All([new Assert\Type('string'), new Assert\Regex('/^[a-h][1-8]$/')])]
    public array $squares = [];

    /** Vision: a choice among the question's (a number of jumps, yes or no, a piece's FEN letter, docs/VISION.md). */
    #[Assert\Regex('/^[A-Za-z0-9_]{1,16}$/')]
    public ?string $choice = null;

    /**
     * Calculation 3.2: the lines played, one against each reply of the opponent's (docs/CALCULATION.md, § 2 quater).
     *
     * @var list<list<string>>
     */
    #[Assert\Count(max: CalculationRules::TWO_REPLIES)]
    #[Assert\All([
        new Assert\Type('array'),
        new Assert\Count(max: 2 * CalculationRules::MAX_MATE),
        new Assert\All([new Assert\Type('string'), new Assert\Regex('/^[a-h][1-8][a-h][1-8][qrbn]?$/')]),
    ])]
    public array $lines = [];

    /**
     * Calculation, chapter 3, and endgames without the tablebase: Stockfish's verdict in the
     * browser, and where the line (the game) went wrong.
     *
     * @var array{right: bool, mistakeAt?: int|null}|null
     */
    #[Assert\Collection(fields: [
        'right' => [new Assert\NotNull(), new Assert\Type('bool')],
        'mistakeAt' => new Assert\Optional([new Assert\Type('int'), new Assert\Range(min: 0, max: EndgameRules::MAX_PLIES)]),
    ])]
    public ?array $engine = null;
}
