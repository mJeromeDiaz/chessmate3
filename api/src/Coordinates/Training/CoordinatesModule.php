<?php

declare(strict_types=1);

namespace App\Coordinates\Training;

use App\Activity\Event\ExerciseCompleted;
use App\Activity\EventPublisher;
use App\Coordinates\Event\SeriesValidated;
use App\Coordinates\Series\CoordinateRules;
use App\Coordinates\Series\SquareDrawer;
use App\Entity\Coordinates\Series;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Activity\ExerciseType;
use App\Enum\Coordinates\Orientation;
use App\Enum\Training\CloseReason;
use App\Enum\Training\Module;
use App\Repository\Coordinates\SeriesRepository;
use App\Training\Exception\InvalidItemSubmissionException;
use App\Training\Exception\InvalidRunConfigException;
use App\Training\Exception\ItemNotFoundException;
use App\Training\Exception\SubjectNotFoundException;
use App\Training\Module\FixedBudgetInterface;
use App\Training\Module\Item;
use App\Training\Module\ItemResult;
use App\Training\Module\ItemSubmission;
use App\Training\Module\PreparedStep;
use App\Training\Module\ReviewableModuleInterface;
use App\Training\Module\ReviewItem;
use App\Training\Module\Summary;
use App\Training\Module\TimeboxedModuleInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The coordinates series in timed runs (docs/COORDINATES.md). The subject is the user; config
 * `orientation` (white or black). The run lasts {@see CoordinateRules::SERIES_SECONDS}, its squares
 * are drawn at the start and served as one item: the client judges each click at once for its
 * feedback and sends the answers in batches, which the server judges again (answer i is about
 * square i; times capped by the server's clock). Closing validates the orientation or not, and
 * logs the series as one `coordinates_series` exercise.
 */
final class CoordinatesModule implements TimeboxedModuleInterface, ReviewableModuleInterface, FixedBudgetInterface
{
    public const SUBJECT_TYPE = 'coordinates_player';
    public const ITEM_TYPE = 'coordinates_series';
    public const REVIEW_TYPE = 'coordinate';
    public const SOURCE_TYPE = 'coordinates_series';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SeriesRepository $series,
        private readonly SquareDrawer $drawer,
        private readonly EventPublisher $events,
    ) {
    }

    public function module(): Module
    {
        return Module::Coordinates;
    }

    public function subjectType(): string
    {
        return self::SUBJECT_TYPE;
    }

    public function fixedBudgetSeconds(): int
    {
        return CoordinateRules::SERIES_SECONDS;
    }

    public function prepare(User $user, array $settings, string $notes): PreparedStep
    {
        $orientation = self::check($settings);

        return new PreparedStep($user->getId(), ['orientation' => $orientation->value]);
    }

    public function start(Run $run, \DateTimeImmutable $now): void
    {
        if (!$run->getSubjectId()->equals($run->getUser()->getId())) {
            throw new SubjectNotFoundException();
        }
        if (CoordinateRules::SERIES_SECONDS !== $run->getBudgetSeconds()) {
            throw new InvalidRunConfigException(sprintf('A coordinates series lasts %d seconds.', CoordinateRules::SERIES_SECONDS));
        }
        $orientation = self::check($run->getConfig());
        $this->entityManager->persist(new Series($run, $orientation, $this->drawer->draw(CoordinateRules::SQUARES_PER_SERIES)));
    }

    public function next(Run $run, \DateTimeImmutable $now): Item
    {
        $series = $this->seriesOf($run);

        return new Item($series->getId()->toRfc4122(), self::ITEM_TYPE, [
            'orientation' => $series->getOrientation()->value,
            'squares' => $series->getSquares(),
            // A reload goes on from there.
            'answered' => $series->getAnswerCount(),
            'successCount' => $series->getSuccessCount(),
            'rules' => CoordinateRules::toArray(),
        ]);
    }

    public function submit(Run $run, ItemSubmission $submission, \DateTimeImmutable $now): ItemResult
    {
        $series = $this->seriesOf($run);
        if ($submission->itemId !== $series->getId()->toRfc4122()) {
            throw new ItemNotFoundException();
        }
        $answers = self::checkAnswers($submission->answers, $series);

        // The client's times are capped by the time elapsed on the server's clock.
        $elapsedMs = min(
            $run->getBudgetSeconds() * 1000,
            self::msBetween($run->getStartedAt(), $now),
        );
        $results = [];
        $allCorrect = true;
        foreach ($answers as $answer) {
            if ($answer['index'] < $series->getAnswerCount()) {
                // Sent again (a retried batch): judged already, kept as it was.
                $correct = $series->isCorrect($answer['index']) ?? false;
            } else {
                $correct = $series->answer($answer['square'], min($answer['ms'], max(0, $elapsedMs - $series->getAnsweredMs())));
            }
            $results[] = ['index' => $answer['index'], 'correct' => $correct];
            $allCorrect = $allCorrect && $correct;
        }

        return new ItemResult(
            $series->getId()->toRfc4122(),
            $allCorrect,
            ['results' => $results, 'answerCount' => $series->getAnswerCount(), 'successCount' => $series->getSuccessCount()],
            $series->isExhausted() ? CloseReason::SubjectFinished : null,
        );
    }

    public function close(Run $run, CloseReason $reason, \DateTimeImmutable $now): void
    {
        $series = $this->seriesOf($run);
        if ($series->isClosed()) {
            return;
        }
        // The runner closes a run whose time is up at its expiry, whenever that is noticed.
        $closedAt = CloseReason::TimeUp === $reason ? min($now, $run->getExpiresAt()) : $now;
        $playedToTheEnd = \in_array($reason, [CloseReason::TimeUp, CloseReason::SubjectFinished], true);
        $validated = CoordinateRules::validates($series->getAnswerCount(), $series->getSuccessCount(), $playedToTheEnd);
        $series->close($validated, $closedAt);
        if (0 === $series->getAnswerCount()) {
            return;
        }

        $userId = $run->getUser()->getId()->toRfc4122();
        $seriesId = $series->getId()->toRfc4122();
        // Same transaction as the closing: the events exist if and only if the series is closed.
        $this->events->publish(new ExerciseCompleted(
            userId: $userId,
            type: ExerciseType::CoordinatesSeries,
            success: $validated,
            durationMs: self::msBetween($run->getStartedAt(), $closedAt),
            itemCount: $series->getAnswerCount(),
            sourceType: self::SOURCE_TYPE,
            sourceId: $seriesId,
            occurredAt: $closedAt,
            metadata: [
                'orientation' => $series->getOrientation()->value,
                'successCount' => $series->getSuccessCount(),
                'validated' => $validated,
                'trainingRunId' => $run->getId()->toRfc4122(),
            ],
        ));
        if ($validated) {
            $this->events->publish(new SeriesValidated(
                userId: $userId,
                seriesId: $seriesId,
                runId: $run->getId()->toRfc4122(),
                orientation: $series->getOrientation()->value,
                answerCount: $series->getAnswerCount(),
                successCount: $series->getSuccessCount(),
                occurredAt: $closedAt,
            ));
        }
    }

    public function summarize(Run $run, \DateTimeImmutable $closedAt): Summary
    {
        $series = $this->seriesOf($run);
        $count = $series->getAnswerCount();

        return new Summary(
            durationMs: self::msBetween($run->getStartedAt(), $closedAt),
            itemCount: $count,
            successCount: $series->getSuccessCount(),
            metrics: [
                'orientation' => $series->getOrientation()->value,
                'validated' => $series->isValidated(),
                'answeredMs' => $series->getAnsweredMs(),
                'averageMs' => $count > 0 ? intdiv($series->getAnsweredMs(), $count) : null,
                'rules' => CoordinateRules::toArray(),
            ],
        );
    }

    public function review(Run $run): array
    {
        $series = $this->series->findOfRun($run);
        if (null === $series) {
            return [];
        }
        $squares = $series->getSquares();
        $items = [];
        foreach ($series->getAnswers() as $index => $answer) {
            $items[] = new ReviewItem(
                self::REVIEW_TYPE,
                $answer['square'] === $squares[$index] ? ReviewItem::OK : ReviewItem::FAIL,
                $answer['ms'],
                ['index' => $index, 'target' => $squares[$index], 'clicked' => $answer['square']],
            );
        }

        return $items;
    }

    private function seriesOf(Run $run): Series
    {
        return $this->series->findOfRun($run) ?? throw new \LogicException('A coordinates run without its series.');
    }

    /**
     * The answers of a batch, checked before any is judged: in order, without a gap after the
     * answers already judged, within the squares drawn.
     *
     * @param list<array{index: int, square: string, ms: int}> $answers
     *
     * @return list<array{index: int, square: string, ms: int}>
     *
     * @throws InvalidItemSubmissionException
     */
    private static function checkAnswers(array $answers, Series $series): array
    {
        if ([] === $answers) {
            throw new InvalidItemSubmissionException('No answer to submit.');
        }
        $expected = null;
        foreach ($answers as $answer) {
            if (!SquareDrawer::isSquare($answer['square']) || $answer['ms'] < 0) {
                throw new InvalidItemSubmissionException('Invalid answer.');
            }
            if (null !== $expected && $answer['index'] !== $expected) {
                throw new InvalidItemSubmissionException('Answers must follow each other.');
            }
            if ($answer['index'] > $series->getAnswerCount() && null === $expected) {
                throw new InvalidItemSubmissionException(sprintf('Answer %d is missing.', $series->getAnswerCount()));
            }
            if ($answer['index'] >= \count($series->getSquares())) {
                throw new InvalidItemSubmissionException('No such square in the series.');
            }
            $expected = $answer['index'] + 1;
        }

        return $answers;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws InvalidRunConfigException
     */
    private static function check(array $config): Orientation
    {
        if ([] !== array_diff(array_keys($config), ['orientation'])) {
            throw new InvalidRunConfigException('Unknown option: only orientation is accepted.');
        }
        $orientation = $config['orientation'] ?? null;

        return (\is_string($orientation) ? Orientation::tryFrom($orientation) : null)
            ?? throw new InvalidRunConfigException(sprintf('orientation must be one of %s.', implode(', ', Orientation::values())));
    }

    private static function msBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return max(0, (int) round(((float) $to->format('U.u') - (float) $from->format('U.u')) * 1000));
    }
}
