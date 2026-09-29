<?php

declare(strict_types=1);

namespace App\Enum\Training;

/**
 * Why a timed run closed (docs/TRAINING.md). Stored as its value: add cases, never rename one.
 */
enum CloseReason: string
{
    /** The budget ran out (closed at its expiry, even when noticed later). */
    case TimeUp = 'time_up';
    /** The user ended the run early. */
    case Stopped = 'stopped';
    /** Nothing left to play: e.g. the last cycle of a classic set was completed. */
    case SubjectFinished = 'subject_finished';
    /** Playable again later: e.g. a classic cycle was completed and the next one is resting. */
    case SubjectResting = 'subject_resting';
    /** The subject can no longer be played: paused, abandoned. */
    case SubjectUnavailable = 'subject_unavailable';
}
