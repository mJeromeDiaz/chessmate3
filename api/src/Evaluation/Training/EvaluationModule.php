<?php

declare(strict_types=1);

namespace App\Evaluation\Training;

use App\Activity\Event\ExerciseCompleted;
use App\Activity\EventPublisher;
use App\Entity\Evaluation\Attempt;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Activity\ExerciseType;
use App\Enum\Evaluation\AttemptStatus;
use App\Enum\Evaluation\Plan;
use App\Enum\Repertoire\Color;
use App\Enum\Training\CloseReason;
use App\Enum\Training\Module;
use App\Evaluation\EvaluationRules;
use App\Repository\Evaluation\AttemptRepository;
use App\Repository\Evaluation\PositionRepository;
use App\Training\Exception\InvalidItemSubmissionException;
use App\Training\Exception\InvalidRunConfigException;
use App\Training\Exception\ItemAlreadySubmittedException;
use App\Training\Exception\ItemNotFoundException;
use App\Training\Exception\SubjectNotFoundException;
use App\Training\Exception\SubjectUnavailableException;
use App\Training\Module\Item;
use App\Training\Module\ItemResult;
use App\Training\Module\ItemSubmission;
use App\Training\Module\PreparedStep;
use App\Training\Module\ReviewableModuleInterface;
use App\Training\Module\ReviewItem;
use App\Training\Module\Summary;
use App\Training\Module\TimeboxedModuleInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Position evaluation in timed runs (docs/EVALUATION.md). The subject is the user; config `count`
 * (positions), `seconds` (per position), `elo` and `side` (white, black or both: the side to
 * move). Each position has its own deadline on the server's clock: an answer after it (network
 * tolerance aside) is "time up", and a position left past it is judged so when the next one is
 * asked. The correction (engine, plan, ideas) only comes with the verdict. The run closes after
 * `count` positions; its budget must leave each of them its time.
 *
 * @phpstan-type Config array{count: int, seconds: int, elo: int, side: string}
 */
final class EvaluationModule implements TimeboxedModuleInterface, ReviewableModuleInterface
{
    public const SUBJECT_TYPE = 'evaluation_player';
    public const ITEM_TYPE = 'evaluation_position';
    public const SOURCE_TYPE = 'evaluation_attempt';
    public const SIDES = ['white', 'black', 'both'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AttemptRepository $attempts,
        private readonly PositionRepository $positions,
        private readonly EventPublisher $events,
    ) {
    }

    public function module(): Module
    {
        return Module::Evaluation;
    }

    public function subjectType(): string
    {
        return self::SUBJECT_TYPE;
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
        $config = self::check($run->getConfig());
        if ($run->getBudgetSeconds() < $config['count'] * $config['seconds']) {
            throw new InvalidRunConfigException(\sprintf('The run must last at least %d seconds (%d positions of %d seconds).', $config['count'] * $config['seconds'], $config['count'], $config['seconds']));
        }
        // Served now: an empty catalogue refuses the run instead of opening an empty one.
        $this->serve($run, $config, $now);
    }

    public function next(Run $run, \DateTimeImmutable $now): Item
    {
        $config = self::check($run->getConfig());
        $attempt = $this->attempts->findPendingOfRun($run);
        if (null !== $attempt && $now > $attempt->deadline(EvaluationRules::TOLERANCE_MS)) {
            // Left on screen past its time (a reload, a lost connection): judged as time up.
            $this->judge($run, $attempt, null, null, $now);
            $attempt = null;
        }
        if (null === $attempt && $this->attempts->countResolvedOfRun($run) >= $config['count']) {
            throw new SubjectUnavailableException(CloseReason::SubjectFinished, [], 'Every position of the run was evaluated.');
        }

        return $this->item($attempt ?? $this->serve($run, $config, $now), $config['count']);
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
        [$guess, $plan] = self::answer($submission->evaluation);
        $late = $now > $attempt->deadline(EvaluationRules::TOLERANCE_MS);
        $this->judge($run, $attempt, $late ? null : $guess, $late ? null : $plan, $now);
        $config = self::check($run->getConfig());
        $finished = $this->attempts->countResolvedOfRun($run) >= $config['count'];

        return new ItemResult(
            itemId: $attempt->getId()->toRfc4122(),
            success: AttemptStatus::Exact === $attempt->getStatus(),
            data: self::correction($attempt),
            closes: $finished ? CloseReason::SubjectFinished : null,
        );
    }

    public function close(Run $run, CloseReason $reason, \DateTimeImmutable $now): void
    {
        $pending = $this->attempts->findPendingOfRun($run);
        if (null === $pending) {
            return;
        }
        // Its time was over: judged. Otherwise the position on screen at the end is not counted.
        if ($now > $pending->deadline(EvaluationRules::TOLERANCE_MS)) {
            $this->judge($run, $pending, null, null, $now);
        } else {
            $this->entityManager->remove($pending);
        }
    }

    public function summarize(Run $run, \DateTimeImmutable $closedAt): Summary
    {
        $stats = ['exact' => 0, 'close' => 0, 'miss' => 0, 'timeout' => 0, 'planOk' => 0, 'activeMs' => 0];
        $attempts = $this->attempts->findResolvedOfRun($run);
        foreach ($attempts as $attempt) {
            $status = $attempt->getStatus()->value;
            if (isset($stats[$status])) {
                ++$stats[$status];
            }
            $stats['planOk'] += true === $attempt->isPlanOk() ? 1 : 0;
            $stats['activeMs'] += $attempt->getDurationMs() ?? 0;
        }
        $played = \count($attempts);
        $config = $run->getConfig();

        return new Summary(
            durationMs: max(0, (int) round(((float) $closedAt->format('U.u') - (float) $run->getStartedAt()->format('U.u')) * 1000)),
            itemCount: $played,
            successCount: $stats['exact'],
            metrics: [
                'count' => $config['count'] ?? null,
                'seconds' => $config['seconds'] ?? null,
                'exact' => $stats['exact'],
                'close' => $stats['close'],
                'miss' => $stats['miss'],
                'timeout' => $stats['timeout'],
                'planOk' => $stats['planOk'],
                'activeMs' => $stats['activeMs'],
                'averageMs' => $played > 0 ? intdiv($stats['activeMs'], $played) : null,
            ],
        );
    }

    public function review(Run $run): array
    {
        return array_map(static fn (Attempt $attempt): ReviewItem => new ReviewItem(
            self::ITEM_TYPE,
            match ($attempt->getStatus()) {
                AttemptStatus::Exact => ReviewItem::OK,
                AttemptStatus::Close => ReviewItem::HINT,
                default => ReviewItem::FAIL,
            },
            $attempt->getDurationMs(),
            [
                'position' => self::position($attempt),
                ...self::correction($attempt),
            ],
        ), $this->attempts->findResolvedOfRun($run));
    }

    /**
     * @param Config $config
     *
     * @throws SubjectUnavailableException no position left for these settings
     */
    private function serve(Run $run, array $config, \DateTimeImmutable $now): Attempt
    {
        $turns = match ($config['side']) {
            'white' => [Color::White],
            'black' => [Color::Black],
            default => [Color::White, Color::Black],
        };
        $position = $this->positions->pick($run, $turns, $config['elo']);
        if (null === $position) {
            throw new SubjectUnavailableException(CloseReason::SubjectUnavailable, ['reason' => 'no_position'], 'No position left for these settings.');
        }
        $attempt = new Attempt($run, $position, $config['seconds'], $now);
        $this->entityManager->persist($attempt);
        $this->entityManager->flush();

        return $attempt;
    }

    /**
     * Judges an attempt and logs it (null guess: no answer in time).
     */
    private function judge(Run $run, Attempt $attempt, ?int $guess, ?Plan $plan, \DateTimeImmutable $now): void
    {
        $position = $attempt->getPosition();
        $status = EvaluationRules::status($guess, EvaluationRules::category($position->getEvalCp()));
        $attempt->resolve($status, $guess, $plan, $now);
        $this->entityManager->flush();

        $this->events->publish(new ExerciseCompleted(
            userId: $run->getUser()->getId()->toRfc4122(),
            type: ExerciseType::PositionEvaluation,
            success: AttemptStatus::Exact === $status,
            durationMs: $attempt->getDurationMs() ?? 0,
            itemCount: 1,
            sourceType: self::SOURCE_TYPE,
            sourceId: $attempt->getId()->toRfc4122(),
            occurredAt: $now,
            metadata: [
                'status' => $status->value,
                'planOk' => $attempt->isPlanOk(),
                'fast' => EvaluationRules::fast($status, $attempt->getDurationMs() ?? 0, $attempt->getSeconds()),
                'position' => $position->getId()->toRfc4122(),
                'guess' => $guess,
                'category' => EvaluationRules::category($position->getEvalCp()),
                'seconds' => $attempt->getSeconds(),
                'trainingRunId' => $run->getId()->toRfc4122(),
            ],
        ));
    }

    /**
     * The position to evaluate: no evaluation, no plan, no idea.
     */
    private function item(Attempt $attempt, int $count): Item
    {
        return new Item($attempt->getId()->toRfc4122(), self::ITEM_TYPE, [
            'position' => self::position($attempt),
            'tip' => $attempt->getPosition()->getTip(),
            // The plan is asked only for a position that has one.
            'askPlan' => null !== $attempt->getPosition()->getPlan(),
            'seconds' => $attempt->getSeconds(),
            'servedAt' => $attempt->getServedAt()->format(\DATE_ATOM),
            'deadlineAt' => $attempt->deadline(0)->format(\DATE_ATOM),
            'index' => $this->attempts->countResolvedOfRun($attempt->getRun()) + 1,
            'count' => $count,
            'plans' => array_map(static fn (Plan $plan): array => ['value' => $plan->value, 'label' => $plan->label()], Plan::cases()),
        ]);
    }

    /**
     * @return array{fen: string, turn: string, tag: string|null, tagLabel: string|null}
     */
    private static function position(Attempt $attempt): array
    {
        $position = $attempt->getPosition();

        return [
            'fen' => $position->getFen(),
            'turn' => $position->getTurn()->value,
            'tag' => $position->getTag()?->value,
            'tagLabel' => $position->getTag()?->label(),
        ];
    }

    /**
     * The verdict and the correction.
     *
     * @return array<string, mixed>
     */
    private static function correction(Attempt $attempt): array
    {
        $position = $attempt->getPosition();

        return [
            'status' => $attempt->getStatus()->value,
            'guess' => $attempt->getGuess(),
            'category' => EvaluationRules::category($position->getEvalCp()),
            'evalCp' => $position->getEvalCp(),
            'engine' => EvaluationRules::label($position->getEvalCp()),
            'plan' => $position->getPlan()?->value,
            'planLabel' => $position->getPlan()?->label(),
            'chosenPlan' => $attempt->getPlan()?->value,
            'planOk' => $attempt->isPlanOk(),
            'ideas' => $position->getIdeas(),
            'source' => $position->getSource(),
            'durationMs' => $attempt->getDurationMs(),
            'fast' => EvaluationRules::fast($attempt->getStatus(), $attempt->getDurationMs() ?? 0, $attempt->getSeconds()),
        ];
    }

    /**
     * @param array<mixed>|null $answer
     *
     * @return array{int|null, Plan|null}
     *
     * @throws InvalidItemSubmissionException
     */
    private static function answer(?array $answer): array
    {
        if (null === $answer) {
            throw new InvalidItemSubmissionException('An evaluation is expected: {guess, plan}.');
        }
        $guess = $answer['guess'] ?? null;
        if (null !== $guess && (!\is_int($guess) || $guess < -2 || $guess > 2)) {
            throw new InvalidItemSubmissionException('guess must be -2 to 2, or null.');
        }
        $plan = $answer['plan'] ?? null;
        if (null !== $plan && (!\is_string($plan) || null === Plan::tryFrom($plan))) {
            throw new InvalidItemSubmissionException(\sprintf('plan must be one of %s, or null.', implode(', ', Plan::values())));
        }

        return [$guess, null === $plan ? null : Plan::from($plan)];
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
        if ([] !== array_diff(array_keys($config), ['count', 'seconds', 'elo', 'side'])) {
            throw new InvalidRunConfigException('Unknown option: only count, seconds, elo and side are accepted.');
        }
        $count = $config['count'] ?? null;
        if (!\is_int($count) || $count < EvaluationRules::MIN_COUNT || $count > EvaluationRules::MAX_COUNT) {
            throw new InvalidRunConfigException(\sprintf('count must be %d to %d.', EvaluationRules::MIN_COUNT, EvaluationRules::MAX_COUNT));
        }
        $seconds = $config['seconds'] ?? null;
        if (!\is_int($seconds) || !\in_array($seconds, EvaluationRules::SECONDS, true)) {
            throw new InvalidRunConfigException(\sprintf('seconds must be one of %s.', implode(', ', EvaluationRules::SECONDS)));
        }
        $elo = $config['elo'] ?? null;
        if (!\is_int($elo) || $elo < EvaluationRules::MIN_ELO || $elo > EvaluationRules::MAX_ELO) {
            throw new InvalidRunConfigException(\sprintf('elo must be %d to %d.', EvaluationRules::MIN_ELO, EvaluationRules::MAX_ELO));
        }
        $side = $config['side'] ?? null;
        if (!\is_string($side) || !\in_array($side, self::SIDES, true)) {
            throw new InvalidRunConfigException(\sprintf('side must be one of %s.', implode(', ', self::SIDES)));
        }

        return ['count' => $count, 'seconds' => $seconds, 'elo' => $elo, 'side' => $side];
    }
}
