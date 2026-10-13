<?php

declare(strict_types=1);

namespace App\Enum\Onboarding;

/**
 * The goal a player picks in the guided tour (docs/ONBOARDING.md): one, kept for later, no effect
 * on the training yet. Stored as its value: add cases, never rename one.
 */
enum Goal: string
{
    /** Gain rating points. */
    case Rating = 'rating';
    /** Stop blundering pieces. */
    case Blunders = 'blunders';
    /** Have fun. */
    case Fun = 'fun';
    /** Understand the moves played. */
    case Understanding = 'understanding';
    /** Draw against Kasparov (the dreamer's goal). */
    case Kasparov = 'kasparov';
}
