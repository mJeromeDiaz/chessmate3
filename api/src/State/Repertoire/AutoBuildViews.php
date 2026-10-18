<?php

declare(strict_types=1);

namespace App\State\Repertoire;

use App\ApiResource\Repertoire\AutoBuild;
use App\Entity\Repertoire\AutoBuild as AutoBuildEntity;
use App\Enum\Repertoire\AutoBuildStatus;
use App\Repertoire\Auto\AutoBuildService;
use App\Repertoire\Auto\Exploration;
use App\Repertoire\Auto\GameRecord;
use App\Repertoire\Auto\LineTag;
use App\Repertoire\Auto\Selection;
use App\Repertoire\Auto\Synthesis;
use App\Repertoire\Limits;

/**
 * The API view of a build: its state, the synthesis once ready, the trees of the openings kept
 * with their lines tagged, numbered and (beyond the free lines, when there is such a limit)
 * locked.
 *
 * @phpstan-import-type FeedItem from AutoBuildService
 */
final readonly class AutoBuildViews
{
    public function __construct(
        private AutoBuildService $builds,
        private LineTag $tags,
        private Limits $limits,
    ) {
    }

    /**
     * @param list<FeedItem>|null $batch
     */
    public function view(AutoBuildEntity $build, ?array $batch = null): AutoBuild
    {
        $view = new AutoBuild();
        $view->id = $build->getId()->toRfc4122();
        $view->status = $build->getStatus()->value;
        $view->username = $build->getUsername();
        $view->maxGames = $build->getMaxGames();
        $view->perfs = $build->getPerfs();
        $view->games = $build->getGameCount();
        $view->scanned = $build->getScanned();
        $view->error = $build->getError();
        $view->batch = $batch;
        $view->repertoireIds = $build->getRepertoireIds();
        $view->expiresAt = $build->getExpiresAt();
        $view->freeLines = $this->limits->autoFreeLines;
        if (AutoBuildStatus::Fetching === $build->getStatus()) {
            return $view;
        }

        $view->synthesis = Synthesis::of(GameRecord::listFromJson($build->getGames()), $this->limits->autoFamilyMinGames);
        if (null === $build->getSelection()) {
            return $view;
        }
        $selection = Selection::fromStored($build->getSelection());
        $view->selection = $selection->toArray();
        $families = array_column($view->synthesis['families'], null, 'key');
        $evaluations = $build->getEvaluations();
        $trees = $this->builds->trees($build);
        $view->pendingEvaluations = \count($this->builds->pendingEvaluations($build, $trees));
        $exploration = Exploration::fromStored($build->getExploration(), $this->limits);
        $view->exploration = [
            'calls' => $exploration->calls,
            'budget' => $exploration->budget,
            'capped' => $exploration->capped,
            'frontier' => \count($this->builds->frontier($trees)),
            'explored' => \count($exploration->replies),
            'rule' => $exploration->rule->toArray(),
        ];
        $view->unconfirmedLines = array_sum(array_map('count', $this->builds->unconfirmedLines($build, $trees)));
        $number = 0;
        foreach ($trees as $key => $tree) {
            $kept = array_flip($selection->kept[$key] ?? []);
            $view->pendingCount += \count($tree->pending);
            $lines = [];
            foreach ($tree->lines as $line) {
                ++$number;
                $evaluated = \array_key_exists($line['fen'], $evaluations);
                $evaluation = LineTag::forUser($evaluations[$line['fen']] ?? null, $tree->color);
                $lines[] = [
                    ...$line,
                    'confirmed' => isset($kept[$line['path']]),
                    'number' => $number,
                    'tag' => $this->tags->of($line['games'], $line['wins'], $line['draws'], $evaluation['cp'], $evaluation['mate']),
                    'evaluated' => $evaluated,
                    'evalCp' => $evaluation['cp'],
                    'evalMate' => $evaluation['mate'],
                    'locked' => null !== $this->limits->autoFreeLines && $number > $this->limits->autoFreeLines,
                ];
            }
            $family = $families[$key] ?? null;
            $view->trees[] = [
                'key' => (string) $key,
                'family' => $family['family'] ?? (string) $key,
                'color' => $tree->color->value,
                'games' => $tree->root['games'],
                'wins' => $tree->root['wins'],
                'draws' => $tree->root['draws'],
                'losses' => $tree->root['losses'],
                'trunk' => $tree->trunk,
                'nodes' => array_values($tree->nodes),
                'lines' => $lines,
                'pending' => $tree->pending,
            ];
        }

        return $view;
    }
}
