<?php

declare(strict_types=1);

namespace App\Puzzle\Training;

use App\ApiResource\Puzzle\PuzzleView;
use App\Entity\Puzzle\Attempt;
use App\Entity\Training\Run;
use App\Enum\Puzzle\AttemptStatus;
use App\Enum\Training\CloseReason;
use App\Enum\Training\Module;
use App\Puzzle\Attempt\AttemptService;
use App\Puzzle\Attempt\Exception\AttemptAlreadySubmittedException;
use App\Puzzle\Attempt\Exception\AttemptNotFoundException;
use App\Puzzle\Attempt\Exception\NoPuzzleAvailableException;
use App\Puzzle\Attempt\Submission;
use App\Puzzle\Selection\SelectionCriteria;
use App\Puzzle\Solution\InvalidSubmissionException;
use App\Repository\Puzzle\AttemptRepository;
use App\Repository\Puzzle\ThemeRepository;
use App\Training\Exception\InvalidItemSubmissionException;
use App\Training\Exception\InvalidRunConfigException;
use App\Training\Exception\ItemAlreadySubmittedException;
use App\Training\Exception\ItemNotFoundException;
use App\Training\Exception\SubjectNotFoundException;
use App\Training\Exception\SubjectUnavailableException;
use App\Training\Module\Item;
use App\Training\Module\ItemResult;
use App\Training\Module\ItemSubmission;
use App\Training\Module\Summary;
use App\Training\Module\TimeboxedModuleInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Rated puzzles in timed runs (docs/TRAINING.md, docs/PUZZLES.md): as many as the time allows,
 * chosen like free play (around the user's rating), optionally restricted to themes (config
 * `themes`, keys combined with OR). The subject is the user. Results go to the Glicko-2 rating
 * exactly as in free play.
 *
 * Rating integrity: the user's pending rated attempt (started in free play, or left on screen when
 * an earlier run ended) is the run's first puzzle; the puzzle on screen when the run ends is not
 * counted but stays pending, so letting the time run out never skips a puzzle.
 */
final class PuzzleModule implements TimeboxedModuleInterface
{
    public const SUBJECT_TYPE = 'puzzle_player';
    public const ITEM_TYPE = 'puzzle';
    public const MAX_THEMES = 10;

    public function __construct(
        private readonly AttemptService $service,
        private readonly AttemptRepository $attempts,
        private readonly ThemeRepository $themes,
    ) {
    }

    public function module(): Module
    {
        return Module::Puzzles;
    }

    public function subjectType(): string
    {
        return self::SUBJECT_TYPE;
    }

    public function start(Run $run, \DateTimeImmutable $now): void
    {
        if (!$run->getSubjectId()->equals($run->getUser()->getId())) {
            throw new SubjectNotFoundException();
        }
        // Serves the first puzzle now: a run with nothing to play is never created.
        $this->serve($run, $now);
    }

    public function next(Run $run, \DateTimeImmutable $now): Item
    {
        return $this->item($this->serve($run, $now));
    }

    public function submit(Run $run, ItemSubmission $submission, \DateTimeImmutable $now): ItemResult
    {
        if (!Uuid::isValid($submission->itemId)) {
            throw new ItemNotFoundException();
        }
        try {
            $attempt = $this->service->submitInRun(
                $run->getUser(),
                Uuid::fromString($submission->itemId),
                new Submission($submission->moves, $submission->hintLevel, $submission->solutionShown),
                $run,
                $now,
            );
        } catch (AttemptNotFoundException) {
            throw new ItemNotFoundException();
        } catch (AttemptAlreadySubmittedException) {
            throw new ItemAlreadySubmittedException();
        } catch (InvalidSubmissionException $e) {
            throw new InvalidItemSubmissionException($e->getMessage(), 0, $e);
        }
        $change = $attempt->getRatingChange();

        return new ItemResult(
            itemId: $attempt->getId()->toRfc4122(),
            success: AttemptStatus::Solved === $attempt->getStatus(),
            data: [
                'status' => $attempt->getStatus()->value,
                'mistakes' => $attempt->getMistakes(),
                'durationMs' => $attempt->getDurationMs(),
                'ratingAfter' => null === $change ? null : round($change->getRatingAfter()),
                'ratingDelta' => null === $change ? null : round($change->getRatingDelta(), 1),
            ],
        );
    }

    public function close(Run $run, CloseReason $reason, \DateTimeImmutable $now): void
    {
        $this->service->detachFromRun($run);
    }

    public function summarize(Run $run, \DateTimeImmutable $closedAt): Summary
    {
        $stats = $this->attempts->statsOfRun($run);
        $played = $stats['solved'] + $stats['failed'];
        $before = $stats['ratingBefore'];
        $after = $stats['ratingAfter'];

        return new Summary(
            durationMs: max(0, (int) round(((float) $closedAt->format('U.u') - (float) $run->getStartedAt()->format('U.u')) * 1000)),
            itemCount: $played,
            successCount: $stats['solved'],
            metrics: [
                'solved' => $stats['solved'],
                'failed' => $stats['failed'],
                'activeMs' => $stats['activeMs'],
                'averageMs' => $played > 0 ? intdiv($stats['activeMs'], $played) : null,
                'themes' => $this->themeKeys($run),
                'ratingBefore' => null === $before ? null : round($before),
                'ratingAfter' => null === $after ? null : round($after),
                'ratingDelta' => null === $before || null === $after ? null : round($after - $before, 1),
            ],
        );
    }

    /**
     * @throws SubjectUnavailableException no puzzle left for the run's themes
     */
    private function serve(Run $run, \DateTimeImmutable $now): Attempt
    {
        try {
            return $this->service->startInRun($run->getUser(), $this->criteria($run), $run, $now);
        } catch (NoPuzzleAvailableException) {
            throw new SubjectUnavailableException(CloseReason::SubjectUnavailable, ['reason' => 'no_puzzle'], 'No puzzle available for these themes.');
        }
    }

    /**
     * @throws InvalidRunConfigException
     */
    private function criteria(Run $run): SelectionCriteria
    {
        $keys = $this->themeKeys($run);
        $themes = $this->themes->findByKeys($keys);
        if (\count($themes) !== \count($keys)) {
            throw new InvalidRunConfigException('Unknown theme.');
        }

        return new SelectionCriteria(array_map(static fn ($theme): int => (int) $theme->getId(), $themes));
    }

    /**
     * @return list<string>
     *
     * @throws InvalidRunConfigException
     */
    private function themeKeys(Run $run): array
    {
        $config = $run->getConfig();
        if ([] !== array_diff(array_keys($config), ['themes'])) {
            throw new InvalidRunConfigException('Unknown option: only themes is accepted.');
        }
        $keys = $config['themes'] ?? [];
        if (!\is_array($keys) || !array_is_list($keys) || \count($keys) > self::MAX_THEMES) {
            throw new InvalidRunConfigException(sprintf('themes must be a list of at most %d theme keys.', self::MAX_THEMES));
        }
        $clean = [];
        foreach ($keys as $key) {
            if (!\is_string($key) || '' === $key || \strlen($key) > 32) {
                throw new InvalidRunConfigException('themes must be a list of theme keys.');
            }
            $clean[] = $key;
        }

        return array_values(array_unique($clean));
    }

    private function item(Attempt $attempt): Item
    {
        $puzzle = PuzzleView::from($attempt->getPuzzle());

        return new Item($attempt->getId()->toRfc4122(), self::ITEM_TYPE, [
            'puzzle' => [
                'id' => $puzzle->id,
                'fen' => $puzzle->fen,
                'moves' => $puzzle->moves,
                'playerColor' => $puzzle->playerColor,
                'rating' => $puzzle->rating,
                'themes' => $puzzle->themes,
                'gameUrl' => $puzzle->gameUrl,
            ],
            'startedAt' => $attempt->getStartedAt()->format(\DATE_ATOM),
        ]);
    }
}
