<?php

declare(strict_types=1);

namespace App\Coach\Session;

use App\Coach\CoachRules;
use App\Coach\Diagnosis\Diagnosis;
use App\Entity\Repertoire\AutoBuild;
use App\Entity\Repertoire\Repertoire;
use App\Entity\Training\Plan;
use App\Entity\User;
use App\Enum\Repertoire\AutoBuildStatus;
use App\Enum\Repertoire\Color;
use App\Enum\Training\Module;
use App\Repertoire\Auto\AutoBuildException;
use App\Repertoire\Auto\AutoBuildService;
use App\Repertoire\Auto\GameRecord;
use App\Repertoire\Auto\Synthesis;
use App\Repertoire\Exception\LimitReachedException;
use App\Repertoire\Limits;
use App\Repertoire\Training\Scope;
use App\Repository\Repertoire\RepertoireRepository;
use App\Training\Exception\InvalidSessionException;
use App\Training\Exception\PlanLimitException;
use App\Training\Plan\PlanManager;
use App\Woodpecker\Set\OpeningSets;
use Psr\Clock\ClockInterface;

/**
 * Composes the tailored session from a build of the user's games (docs/COACH.md), always the same
 * way: Woodpecker on the most played opening with White, then with Black (an opening set each,
 * {@see OpeningSets}), the repertoire (the user's repertoires; none: generated from these games
 * when asked, with the most played opening of each colour), blindfold puzzles, position
 * evaluation. Saved as the user's coach plan ({@see Plan::ORIGIN_COACH}), replaced each time.
 *
 * Each module comes with the facts the page tells about it; a module that cannot be played (no
 * opening, too few puzzles, no repertoire) is listed with the reason, not saved in the plan.
 *
 * @phpstan-import-type DiagnosisArray from Diagnosis
 *
 * @phpstan-type ModuleArray array{module: string, minutes: int, included: bool, missing: string|null, facts: array<string, mixed>}
 * @phpstan-type CompositionArray array{planId: string, minutes: int, diagnosis: DiagnosisArray, repertoire: array{status: string, repertoires: list<array{id: string, name: string, color: string}>}, modules: list<ModuleArray>}
 */
final class Composer
{
    public function __construct(
        private readonly AutoBuildService $builds,
        private readonly OpeningSets $openingSets,
        private readonly RepertoireRepository $repertoires,
        private readonly PlanManager $plans,
        private readonly Limits $limits,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return CompositionArray
     *
     * @throws CoachException      not_ready (the games are still being read)
     * @throws PlanLimitException  the user has as many plans as allowed
     */
    public function compose(User $user, AutoBuild $build, bool $withRepertoire): array
    {
        if (AutoBuildStatus::Fetching === $build->getStatus()) {
            throw new CoachException(CoachException::NOT_READY);
        }
        $games = GameRecord::listFromJson($build->getGames());
        $diagnosis = Diagnosis::of($games);

        $modules = [
            $this->woodpecker($user, $diagnosis, Color::White),
            $this->woodpecker($user, $diagnosis, Color::Black),
        ];
        $repertoire = $this->repertoire($user, $build, $games, $withRepertoire);
        $modules[] = [
            'module' => Module::Repertoire->value,
            'minutes' => CoachRules::REPERTOIRE_MINUTES,
            'included' => [] !== $repertoire['repertoires'],
            'missing' => [] === $repertoire['repertoires'] ? $repertoire['status'] : null,
            'facts' => ['settings' => ['repertoireIds' => array_column($repertoire['repertoires'], 'id')], 'repertoires' => $repertoire['repertoires']],
        ];
        $modules[] = [
            'module' => Module::Blindfold->value,
            'minutes' => CoachRules::BLINDFOLD_MINUTES,
            'included' => true,
            'missing' => null,
            'facts' => [
                'settings' => ['level' => CoachRules::blindfoldLevel($diagnosis['rating'])->value, 'length' => CoachRules::BLINDFOLD_LENGTH, 'visibleSeconds' => CoachRules::BLINDFOLD_VISIBLE_SECONDS],
                'weakest' => $diagnosis['weakest'],
            ],
        ];
        $modules[] = [
            'module' => Module::Evaluation->value,
            'minutes' => CoachRules::EVALUATION_MINUTES,
            'included' => true,
            'missing' => null,
            'facts' => [
                'settings' => ['elo' => CoachRules::evaluationElo($diagnosis['rating']), 'side' => 'both'],
                'endgames' => ['games' => $diagnosis['endgames']['games'], 'advantages' => $diagnosis['endgames']['advantages'], 'lost' => $diagnosis['endgames']['lost']],
            ],
        ];

        $steps = [];
        foreach ($modules as $module) {
            if ($module['included']) {
                $steps[] = ['module' => Module::from($module['module']), 'minutes' => $module['minutes'], 'notes' => '', 'settings' => self::settings($module['facts']['settings'] ?? null)];
            }
        }
        $day = $this->clock->now()->setTimezone($user->getDateTimeZone())->format('d/m/Y');
        try {
            $plan = $this->plans->saveForOrigin($user, Plan::ORIGIN_COACH, CoachRules::PLAN_TITLE, \sprintf('Tirée de tes %d dernières parties Lichess, le %s.', $diagnosis['games'], $day), $steps);
        } catch (InvalidSessionException $e) {
            // Every step was just checked by its module: a bug, not the user's doing.
            throw new \LogicException('The tailored session is invalid: '.$e->getMessage(), 0, $e);
        }

        return [
            'planId' => $plan->getId()->toRfc4122(),
            'minutes' => array_sum(array_column($steps, 'minutes')),
            'diagnosis' => $diagnosis,
            'repertoire' => $repertoire,
            'modules' => $modules,
        ];
    }

    /**
     * @param DiagnosisArray $diagnosis
     *
     * @return ModuleArray
     */
    private function woodpecker(User $user, array $diagnosis, Color $color): array
    {
        $opening = $diagnosis[$color->value][0] ?? null;
        $set = null === $opening ? null : $this->openingSets->ensure($user, $opening['family'], $color);
        $pool = $set?->getOpening();

        return [
            'module' => Module::Woodpecker->value,
            'minutes' => CoachRules::WOODPECKER_MINUTES,
            'included' => null !== $set,
            'missing' => null === $opening ? 'no_games' : (null === $set ? 'no_puzzles' : null),
            'facts' => [
                'settings' => null === $set ? [] : ['setId' => $set->getId()->toRfc4122()],
                'color' => $color->value,
                'opening' => $opening,
                'puzzleCount' => $set?->getPuzzleCount(),
                'bothColors' => $pool?->bothColors,
            ],
        ];
    }

    /**
     * The user's repertoires; none: generated from these games when asked. Status: existing,
     * generated, not_asked, no_opening (no opening played often enough), failed.
     *
     * @param list<GameRecord> $games
     *
     * @return array{status: string, repertoires: list<array{id: string, name: string, color: string}>}
     */
    private function repertoire(User $user, AutoBuild $build, array $games, bool $withRepertoire): array
    {
        $existing = \array_slice($this->repertoires->findByUser($user), 0, Scope::MAX_REPERTOIRES);
        if ([] !== $existing) {
            return ['status' => 'existing', 'repertoires' => self::views($existing)];
        }
        if (!$withRepertoire) {
            return ['status' => 'not_asked', 'repertoires' => []];
        }

        $families = [];
        foreach (Synthesis::of($games, $this->limits->autoFamilyMinGames)['families'] as $family) {
            $families[$family['color']] ??= $family['key'];
        }
        if ([] === $families) {
            return ['status' => 'no_opening', 'repertoires' => []];
        }
        try {
            $this->builds->select($build, array_values($families), [], []);
            $ids = $this->builds->apply($build);
        } catch (AutoBuildException|LimitReachedException) {
            return ['status' => 'failed', 'repertoires' => []];
        }
        $created = [];
        foreach ($this->repertoires->findByUser($user) as $repertoire) {
            if (\in_array($repertoire->getId()->toRfc4122(), $ids, true)) {
                $created[] = $repertoire;
            }
        }

        return ['status' => 'generated', 'repertoires' => self::views($created)];
    }

    /**
     * A module's step settings, as its facts carry them.
     *
     * @return array<string, mixed>
     */
    private static function settings(mixed $settings): array
    {
        $kept = [];
        foreach (\is_array($settings) ? $settings : [] as $key => $value) {
            if (\is_string($key)) {
                $kept[$key] = $value;
            }
        }

        return $kept;
    }

    /**
     * @param list<Repertoire> $repertoires
     *
     * @return list<array{id: string, name: string, color: string}>
     */
    private static function views(array $repertoires): array
    {
        return array_map(static fn (Repertoire $repertoire): array => [
            'id' => $repertoire->getId()->toRfc4122(),
            'name' => $repertoire->getName(),
            'color' => $repertoire->getColor()->value,
        ], $repertoires);
    }
}
