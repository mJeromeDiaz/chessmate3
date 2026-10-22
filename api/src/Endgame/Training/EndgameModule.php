<?php

declare(strict_types=1);

namespace App\Endgame\Training;

use App\Activity\Event\ExerciseCompleted;
use App\Activity\EventPublisher;
use App\Endgame\EndgameRules;
use App\Endgame\Judge\GameJudge;
use App\Endgame\Lesson\Catalogue;
use App\Endgame\Lesson\Lesson;
use App\Endgame\Pool\PositionPool;
use App\Endgame\Tablebase\Tablebase;
use App\Entity\Endgame\Attempt;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Activity\ExerciseType;
use App\Enum\Endgame\AttemptStatus;
use App\Enum\Endgame\Family;
use App\Enum\Endgame\Goal;
use App\Enum\Training\CloseReason;
use App\Enum\Training\Module;
use App\Repository\Endgame\AttemptRepository;
use App\Training\Exception\InvalidRunConfigException;
use App\Training\Exception\ItemAlreadySubmittedException;
use App\Training\Exception\ItemNotFoundException;
use App\Training\Exception\SubjectNotFoundException;
use App\Training\Module\BudgetChoicesInterface;
use App\Training\Module\Item;
use App\Training\Module\ItemResult;
use App\Training\Module\ItemSubmission;
use App\Training\Module\PreparedStep;
use App\Training\Module\ReviewableModuleInterface;
use App\Training\Module\ReviewItem;
use App\Training\Module\Summary;
use App\Training\Module\TimeboxedModuleInterface;
use Doctrine\ORM\EntityManagerInterface;
use Random\Randomizer;
use Symfony\Component\Uid\Uuid;

/**
 * The endgames module in timed runs (docs/ENDGAMES.md, § 5). The subject is the user; config
 * `mode` (learn: each lesson's textbook position then {@see EndgameRules::LEARN_VARIANTS} variants;
 * review: variants of lessons at random), `themes` ({@see Family} values) or `lessons` (keys, the
 * free page's choice), and `elo` (Maia's; {@see EndgameRules::MAX_ELO}: the tablebase's best
 * defence). A run lasts one of {@see EndgameRules::RUN_MINUTES}; positions follow one another until
 * its time is up, the one on screen at the end not counted.
 *
 * The browser plays the game (Maia, the tablebase's answers asked after each move) and sends it
 * whole; {@see GameJudge} judges it again from the answers kept.
 *
 * @phpstan-type Config array{mode: string, themes: list<string>, lessons: list<string>, elo: int}
 */
final class EndgameModule implements TimeboxedModuleInterface, ReviewableModuleInterface, BudgetChoicesInterface
{
    public const SUBJECT_TYPE = 'endgame_player';
    public const ITEM_TYPE = 'endgame_position';
    public const SOURCE_TYPE = 'endgame_attempt';
    public const MODES = ['learn', 'review'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AttemptRepository $attempts,
        private readonly PositionPool $pool,
        private readonly Tablebase $tablebase,
        private readonly GameJudge $judge,
        private readonly EventPublisher $events,
    ) {
    }

    public function module(): Module
    {
        return Module::Endgames;
    }

    public function subjectType(): string
    {
        return self::SUBJECT_TYPE;
    }

    public function allowedBudgetSeconds(): array
    {
        return EndgameRules::budgets();
    }

    public function prepare(User $user, array $settings, string $notes): PreparedStep
    {
        return new PreparedStep($user->getId(), self::check($settings));
    }

    public function start(Run $run, \DateTimeImmutable $now): void
    {
        if (!$run->getSubjectId()->equals($run->getUser()->getId())) {
            throw new SubjectNotFoundException();
        }
        if (!EndgameRules::allows($run->getBudgetSeconds())) {
            throw new InvalidRunConfigException(\sprintf('A run lasts %s minutes.', implode(', ', EndgameRules::RUN_MINUTES)));
        }
        $this->serve($run, self::check($run->getConfig()), $now);
    }

    public function next(Run $run, \DateTimeImmutable $now): Item
    {
        // A reload gives the same position back, to play again from its start.
        $attempt = $this->attempts->findPendingOfRun($run) ?? $this->serve($run, self::check($run->getConfig()), $now);

        return $this->item($attempt);
    }

    public function submit(Run $run, ItemSubmission $submission, \DateTimeImmutable $now): ItemResult
    {
        $attempt = Uuid::isValid($submission->itemId) ? $this->attempts->findOwned(Uuid::fromString($submission->itemId), $run->getUser()) : null;
        if (null === $attempt || !$attempt->getRun()->getId()->equals($run->getId())) {
            throw new ItemNotFoundException();
        }
        if (!$attempt->isPending()) {
            throw new ItemAlreadySubmittedException();
        }
        $lesson = Catalogue::get($attempt->getLessonKey()) ?? throw new ItemNotFoundException();

        $verdict = $this->judge->judge($lesson, $attempt->getFen(), $submission->moves, $attempt->getBudgetMoves(), $attempt->getHoldMoves(), null === $attempt->getOpponentElo(), $submission->pass, $submission->engine);
        $attempt->resolve($verdict->status, $submission->moves, $verdict->mistakeAt, $verdict->bestMove, $verdict->playerMoves, $verdict->engineJudged, $now);
        $this->entityManager->flush();
        $this->publish($run, $attempt, $lesson, $now);

        return new ItemResult(
            itemId: $attempt->getId()->toRfc4122(),
            success: $verdict->status->isSuccess(),
            data: self::correction($attempt),
        );
    }

    public function close(Run $run, CloseReason $reason, \DateTimeImmutable $now): void
    {
        // The position on screen at the end is not counted.
        $pending = $this->attempts->findPendingOfRun($run);
        if (null !== $pending) {
            $this->entityManager->remove($pending);
        }
    }

    public function summarize(Run $run, \DateTimeImmutable $closedAt): Summary
    {
        $stats = array_fill_keys(array_map(static fn (AttemptStatus $status): string => $status->value, AttemptStatus::cases()), 0);
        $engineJudged = 0;
        $attempts = $this->attempts->findResolvedOfRun($run);
        foreach ($attempts as $attempt) {
            ++$stats[$attempt->getStatus()->value];
            $engineJudged += $attempt->isEngineJudged() ? 1 : 0;
        }
        unset($stats[AttemptStatus::Pending->value]);

        return new Summary(
            durationMs: max(0, (int) round(((float) $closedAt->format('U.u') - (float) $run->getStartedAt()->format('U.u')) * 1000)),
            itemCount: \count($attempts),
            successCount: $stats[AttemptStatus::Won->value] + $stats[AttemptStatus::Held->value],
            metrics: [...$stats, 'engineJudged' => $engineJudged],
        );
    }

    public function review(Run $run): array
    {
        return array_map(static fn (Attempt $attempt): ReviewItem => new ReviewItem(
            self::ITEM_TYPE,
            $attempt->getStatus()->isSuccess() ? ReviewItem::OK : ReviewItem::FAIL,
            $attempt->getDurationMs(),
            ['fen' => $attempt->getFen(), ...self::correction($attempt)],
        ), $this->attempts->findResolvedOfRun($run));
    }

    /**
     * The next position: in learn mode, the lessons in order, each one's textbook position then
     * its variants; in review mode, a variant of a lesson at random. A lesson without variants in
     * the pool serves its textbook position.
     *
     * @param Config $config
     */
    private function serve(Run $run, array $config, \DateTimeImmutable $now): Attempt
    {
        $lessons = self::lessons($config);
        $served = $this->attempts->servedPositionIds($run);
        $randomizer = new Randomizer();
        $position = null;
        if ('learn' === $config['mode']) {
            $index = $this->attempts->countResolvedOfRun($run);
            $lesson = $lessons[intdiv($index, 1 + EndgameRules::LEARN_VARIANTS) % \count($lessons)];
            $position = 0 === $index % (1 + EndgameRules::LEARN_VARIANTS) ? null : $this->pool->pick($lesson, $randomizer, $served);
        } else {
            $lesson = $lessons[0];
            foreach ($randomizer->shuffleArray($lessons) as $candidate) {
                $lesson = $candidate;
                $position = $this->pool->pick($candidate, $randomizer, $served);
                if (null !== $position) {
                    break;
                }
            }
        }

        $fen = $position['fen'] ?? $lesson->textbook;
        // Kept answers only: no call to Lichess inside the run's transaction.
        $dtm = null === $position ? $this->tablebase->lookup($fen)?->dtm : $position['dtm'];
        $attempt = new Attempt(
            $run,
            $lesson->key,
            $fen,
            $position['id'] ?? null,
            null === $position,
            EndgameRules::isPerfect($config['elo']) ? null : $config['elo'],
            Goal::Hold === $lesson->goal ? null : EndgameRules::budgetMoves($dtm),
            Goal::Hold === $lesson->goal ? $lesson->holdMoves : null,
            $now,
        );
        $this->entityManager->persist($attempt);
        $this->entityManager->flush();

        return $attempt;
    }

    private function item(Attempt $attempt): Item
    {
        $lesson = Catalogue::get($attempt->getLessonKey());
        $config = self::check($attempt->getRun()->getConfig());
        $index = $this->attempts->countResolvedOfRun($attempt->getRun()) + 1;

        return new Item($attempt->getId()->toRfc4122(), self::ITEM_TYPE, [
            'fen' => $attempt->getFen(),
            'lesson' => $attempt->getLessonKey(),
            'family' => $lesson?->family->value,
            'goal' => $lesson?->goal->value,
            'result' => $lesson?->result->value,
            'textbook' => $attempt->isTextbook(),
            // Lizy explains the lesson before its first position, in learn mode (the textbook
            // one; it comes back in place of a variant when the pool has none, unexplained).
            'explain' => 'learn' === $config['mode'] && $attempt->isTextbook() && 0 === ($index - 1) % (1 + EndgameRules::LEARN_VARIANTS),
            'budgetMoves' => $attempt->getBudgetMoves(),
            'holdMoves' => $attempt->getHoldMoves(),
            'opponent' => ['elo' => $attempt->getOpponentElo(), 'perfect' => null === $attempt->getOpponentElo()],
            'servedAt' => $attempt->getServedAt()->format(\DATE_ATOM),
            'index' => $index,
        ]);
    }

    private function publish(Run $run, Attempt $attempt, Lesson $lesson, \DateTimeImmutable $now): void
    {
        $this->events->publish(new ExerciseCompleted(
            userId: $run->getUser()->getId()->toRfc4122(),
            type: ExerciseType::Endgame,
            success: $attempt->getStatus()->isSuccess(),
            durationMs: $attempt->getDurationMs() ?? 0,
            itemCount: 1,
            sourceType: self::SOURCE_TYPE,
            sourceId: $attempt->getId()->toRfc4122(),
            occurredAt: $now,
            metadata: [
                'status' => $attempt->getStatus()->value,
                'lesson' => $lesson->key,
                'family' => $lesson->family->value,
                'opponentElo' => $attempt->getOpponentElo(),
                'textbook' => $attempt->isTextbook(),
                'playerMoves' => $attempt->getPlayerMoves(),
                'engineJudged' => $attempt->isEngineJudged(),
                'trainingRunId' => $run->getId()->toRfc4122(),
            ],
        ));
    }

    /**
     * The verdict and the correction.
     *
     * @return array<string, mixed>
     */
    private static function correction(Attempt $attempt): array
    {
        return [
            'status' => $attempt->getStatus()->value,
            'lesson' => $attempt->getLessonKey(),
            'moves' => $attempt->getMoves() ?? [],
            'mistakeAt' => $attempt->getMistakeAt(),
            'bestMove' => $attempt->getBestMove(),
            'playerMoves' => $attempt->getPlayerMoves(),
            'budgetMoves' => $attempt->getBudgetMoves(),
            'holdMoves' => $attempt->getHoldMoves(),
            'engineJudged' => $attempt->isEngineJudged(),
            'durationMs' => $attempt->getDurationMs(),
        ];
    }

    /**
     * The lessons a run plays: those chosen, else those of its themes, in the catalogue's order.
     *
     * @param Config $config
     *
     * @return non-empty-list<Lesson>
     */
    private static function lessons(array $config): array
    {
        $lessons = [] !== $config['lessons']
            ? array_values(array_filter(array_map(Catalogue::get(...), $config['lessons'])))
            : array_values(array_filter(Catalogue::all(), static fn (Lesson $lesson): bool => \in_array($lesson->family->value, $config['themes'], true)));

        return [] === $lessons ? throw new InvalidRunConfigException('No lesson to play.') : $lessons;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return Config
     *
     * @throws InvalidRunConfigException
     */
    private static function check(array $config): array
    {
        if ([] !== array_diff(array_keys($config), ['mode', 'themes', 'lessons', 'elo'])) {
            throw new InvalidRunConfigException('Unknown option: only mode, themes, lessons and elo are accepted.');
        }
        $mode = $config['mode'] ?? null;
        if (!\is_string($mode) || !\in_array($mode, self::MODES, true)) {
            throw new InvalidRunConfigException(\sprintf('mode must be one of %s.', implode(', ', self::MODES)));
        }
        $families = array_map(static fn (Family $family): string => $family->value, Family::cases());
        $given = $config['themes'] ?? [];
        $themes = [];
        foreach (\is_array($given) && array_is_list($given) ? $given : [null] as $theme) {
            if (!\is_string($theme) || !\in_array($theme, $families, true) || \in_array($theme, $themes, true)) {
                throw new InvalidRunConfigException(\sprintf('themes must be distinct values among %s.', implode(', ', $families)));
            }
            $themes[] = $theme;
        }
        $lessons = $config['lessons'] ?? [];
        if (!\is_array($lessons) || !array_is_list($lessons) || [] !== array_filter($lessons, static fn (mixed $key): bool => !\is_string($key) || null === Catalogue::get($key))) {
            throw new InvalidRunConfigException('lessons must be keys of the catalogue.');
        }
        if ([] === $themes && [] === $lessons) {
            throw new InvalidRunConfigException('Choose themes or lessons.');
        }
        $elo = $config['elo'] ?? null;
        if (!\is_int($elo) || $elo < EndgameRules::MIN_ELO || $elo > EndgameRules::MAX_ELO) {
            throw new InvalidRunConfigException(\sprintf('elo must be %d to %d.', EndgameRules::MIN_ELO, EndgameRules::MAX_ELO));
        }

        /** @var list<string> $lessons */
        return ['mode' => $mode, 'themes' => $themes, 'lessons' => $lessons, 'elo' => $elo];
    }
}
