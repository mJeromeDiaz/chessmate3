<?php

declare(strict_types=1);

namespace App\Training\Session;

use App\Entity\User;
use App\Enum\Training\Module;
use App\Training\Exception\InvalidRunConfigException;
use App\Training\Exception\InvalidSessionException;
use App\Training\Exception\SubjectNotFoundException;
use App\Training\Exception\SubjectUnavailableException;
use App\Training\Module\FixedBudgetInterface;
use App\Training\Module\ModuleRegistry;
use App\Training\Module\PreparedStep;
use App\Training\Run\TimeboxRunner;

/**
 * Checks a program's steps with their modules ({@see \App\Training\Module\TimeboxedModuleInterface::prepare()}):
 * length and settings always; whether the subject can be played now only at a launch (a saved
 * session may wait for its light set to be resumed).
 */
final class StepChecker
{
    public function __construct(
        private readonly ModuleRegistry $modules,
    ) {
    }

    /**
     * @param list<array{module: Module, minutes: int, notes: string, settings: array<string, mixed>}> $steps
     *
     * @throws InvalidSessionException a step is invalid (its number in the message)
     */
    public function check(User $user, array $steps, bool $playableNow): void
    {
        foreach ($steps as $i => $step) {
            if ($step['minutes'] < 1 || $step['minutes'] * 60 > TimeboxRunner::MAX_BUDGET_SECONDS) {
                throw new InvalidSessionException(sprintf('Step %d: 1 to %d minutes.', $i + 1, intdiv(TimeboxRunner::MAX_BUDGET_SECONDS, 60)));
            }
            $implementation = $this->modules->for($step['module']);
            if ($implementation instanceof FixedBudgetInterface && $step['minutes'] * 60 !== $implementation->fixedBudgetSeconds()) {
                throw new InvalidSessionException(sprintf('Step %d: exactly %d minutes.', $i + 1, intdiv($implementation->fixedBudgetSeconds(), 60)));
            }
            try {
                $this->prepare($user, $step['module'], $step['settings'], $step['notes']);
            } catch (SubjectUnavailableException $e) {
                if ($playableNow) {
                    throw new InvalidSessionException(sprintf('Step %d: %s', $i + 1, $e->getMessage()), 0, $e);
                }
            } catch (InvalidRunConfigException|SubjectNotFoundException $e) {
                throw new InvalidSessionException(sprintf('Step %d: %s', $i + 1, $e->getMessage() ?: 'subject not found.'), 0, $e);
            }
        }
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @throws InvalidRunConfigException
     * @throws SubjectNotFoundException
     * @throws SubjectUnavailableException
     */
    public function prepare(User $user, Module $module, array $settings, string $notes): PreparedStep
    {
        return $this->modules->for($module)->prepare($user, $settings, $notes);
    }
}
