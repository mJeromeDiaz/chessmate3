<?php

declare(strict_types=1);

namespace App\Training\Module;

use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Training\CloseReason;
use App\Enum\Training\Module;
use App\Training\Exception\InvalidItemSubmissionException;
use App\Training\Exception\InvalidRunConfigException;
use App\Training\Exception\ItemAlreadySubmittedException;
use App\Training\Exception\ItemClosedException;
use App\Training\Exception\ItemNotFoundException;
use App\Training\Exception\SubjectNotFoundException;
use App\Training\Exception\SubjectUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The contract of a module played in timed runs (docs/TRAINING.md). Time belongs to
 * {@see \App\Training\Run\TimeboxRunner}: it calls these methods inside its transaction, run
 * locked, only while the run may still serve or accept items. A module locks its subject after
 * the run, never the other way round, and outcomes and durations are computed server-side.
 */
#[AutoconfigureTag(self::TAG)]
interface TimeboxedModuleInterface
{
    public const TAG = 'app.training.module';

    public function module(): Module;

    /** What {@see Run::getSubjectId()} refers to (e.g. woodpecker_set). */
    public function subjectType(): string;

    /**
     * Turns the settings of a session step (docs/TRAINING.md, sessions) into this module's run
     * subject and config, checking them: at the session's launch, and again when the step starts
     * (the subject may have changed meanwhile, e.g. the light set paused).
     *
     * @param array<string, mixed> $settings
     *
     * @throws InvalidRunConfigException   invalid settings
     * @throws SubjectNotFoundException    unknown subject, or another user's
     * @throws SubjectUnavailableException no subject to play now
     */
    public function prepare(User $user, array $settings, string $notes): PreparedStep;

    /**
     * Checks the subject can be played and prepares it for the run (already persisted).
     *
     * @throws InvalidRunConfigException   invalid options in the run's config
     * @throws SubjectNotFoundException    unknown subject, or another user's
     * @throws SubjectUnavailableException not playable now (the run is not created)
     */
    public function start(Run $run, \DateTimeImmutable $now): void;

    /**
     * The item to play: the one already served in this run and not submitted yet (a reload or a
     * reconnection gets it back, timer running), or a new one.
     *
     * @throws SubjectUnavailableException the run must close ({@see SubjectUnavailableException::$reason})
     */
    public function next(Run $run, \DateTimeImmutable $now): Item;

    /**
     * Resolves an item served in this run.
     *
     * @throws ItemNotFoundException          not an item of this run
     * @throws ItemAlreadySubmittedException
     * @throws ItemClosedException            the item can no longer be submitted, the run goes on
     * @throws InvalidItemSubmissionException
     * @throws SubjectUnavailableException    the run must close
     */
    public function submit(Run $run, ItemSubmission $submission, \DateTimeImmutable $now): ItemResult;

    /**
     * The run is closing: an item served but not submitted is dropped (not counted).
     */
    public function close(Run $run, CloseReason $reason, \DateTimeImmutable $now): void;

    /**
     * The recap of the run, closing at $closedAt.
     */
    public function summarize(Run $run, \DateTimeImmutable $closedAt): Summary;
}
