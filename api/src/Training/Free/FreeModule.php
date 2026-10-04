<?php

declare(strict_types=1);

namespace App\Training\Free;

use App\Activity\Event\ExerciseCompleted;
use App\Activity\EventPublisher;
use App\Entity\Training\Run;
use App\Enum\Activity\ExerciseType;
use App\Enum\Training\CloseReason;
use App\Enum\Training\Module;
use App\Training\Exception\InvalidItemSubmissionException;
use App\Training\Exception\InvalidRunConfigException;
use App\Training\Exception\SubjectNotFoundException;
use App\Training\Module\Item;
use App\Training\Module\ItemResult;
use App\Training\Module\ItemSubmission;
use App\Training\Module\Summary;
use App\Training\Module\TimeboxedModuleInterface;

/**
 * Free study in timed runs (docs/TRAINING.md): the user reads a book, watches a video... while the
 * server times it. The subject is the user; config `format` (book, video, course, podcast, other)
 * and optional `notes`. One item, the timer, with nothing to submit: the run ends with "Terminer"
 * or at its expiry, and its real duration is logged as a `free_study` exercise.
 */
final class FreeModule implements TimeboxedModuleInterface
{
    public const SUBJECT_TYPE = 'free_owner';
    public const ITEM_TYPE = 'free_timer';
    public const SOURCE_TYPE = 'training_run';
    public const FORMATS = ['book', 'video', 'course', 'podcast', 'other'];
    public const MAX_NOTES_LENGTH = 500;

    public function __construct(
        private readonly EventPublisher $events,
    ) {
    }

    public function module(): Module
    {
        return Module::Free;
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
        self::options($run);
    }

    public function next(Run $run, \DateTimeImmutable $now): Item
    {
        [$format, $notes] = self::options($run);

        return new Item($run->getId()->toRfc4122(), self::ITEM_TYPE, ['format' => $format, 'notes' => $notes]);
    }

    public function submit(Run $run, ItemSubmission $submission, \DateTimeImmutable $now): ItemResult
    {
        throw new InvalidItemSubmissionException('A free study run has nothing to submit: stop it.');
    }

    public function close(Run $run, CloseReason $reason, \DateTimeImmutable $now): void
    {
        // The runner closes a run whose time is up at its expiry, whenever that is noticed.
        $closedAt = CloseReason::TimeUp === $reason ? min($now, $run->getExpiresAt()) : $now;
        $durationMs = self::durationMs($run, $closedAt);
        if ($durationMs <= 0) {
            return;
        }
        [$format] = self::options($run);
        // Same transaction as the closing: the event exists if and only if the run is closed.
        $this->events->publish(new ExerciseCompleted(
            userId: $run->getUser()->getId()->toRfc4122(),
            type: ExerciseType::FreeStudy,
            success: true,
            durationMs: $durationMs,
            itemCount: 1,
            sourceType: self::SOURCE_TYPE,
            sourceId: $run->getId()->toRfc4122(),
            occurredAt: $closedAt,
            metadata: ['format' => $format, 'trainingRunId' => $run->getId()->toRfc4122()],
        ));
    }

    public function summarize(Run $run, \DateTimeImmutable $closedAt): Summary
    {
        [$format, $notes] = self::options($run);

        return new Summary(
            durationMs: self::durationMs($run, $closedAt),
            itemCount: 0,
            successCount: 0,
            metrics: ['format' => $format, 'notes' => $notes],
        );
    }

    private static function durationMs(Run $run, \DateTimeImmutable $closedAt): int
    {
        return max(0, (int) round(((float) $closedAt->format('U.u') - (float) $run->getStartedAt()->format('U.u')) * 1000));
    }

    /**
     * @return array{string, string}
     *
     * @throws InvalidRunConfigException
     */
    private static function options(Run $run): array
    {
        $config = $run->getConfig();
        if ([] !== array_diff(array_keys($config), ['format', 'notes'])) {
            throw new InvalidRunConfigException('Unknown option: only format and notes are accepted.');
        }
        $format = $config['format'] ?? null;
        if (!\in_array($format, self::FORMATS, true)) {
            throw new InvalidRunConfigException(sprintf('format must be one of %s.', implode(', ', self::FORMATS)));
        }
        $notes = $config['notes'] ?? '';
        if (!\is_string($notes) || mb_strlen($notes) > self::MAX_NOTES_LENGTH) {
            throw new InvalidRunConfigException(sprintf('notes must be a text of at most %d characters.', self::MAX_NOTES_LENGTH));
        }

        return [$format, $notes];
    }
}
