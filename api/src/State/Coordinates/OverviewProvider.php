<?php

declare(strict_types=1);

namespace App\State\Coordinates;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Coordinates\Overview;
use App\Coordinates\Series\CoordinateRules;
use App\Entity\Coordinates\Series;
use App\Enum\Coordinates\Orientation;
use App\Repository\Coordinates\SeriesRepository;
use App\Security\AuthenticatedUser;
use App\Training\Run\TimeboxRunner;

/**
 * GET /coordinates: the current user's only. A series left behind is closed first
 * (lazy closing), so that it counts.
 *
 * @phpstan-import-type SeriesView from Overview
 *
 * @implements ProviderInterface<Overview>
 */
final class OverviewProvider implements ProviderInterface
{
    public const HISTORY_SIZE = 20;

    public function __construct(
        private readonly SeriesRepository $series,
        private readonly TimeboxRunner $runner,
        private readonly AuthenticatedUser $authenticatedUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Overview
    {
        $user = $this->authenticatedUser->get();
        $this->runner->closeExpired($user);

        $view = new Overview();
        $view->rules = CoordinateRules::toArray();
        foreach (Orientation::cases() as $orientation) {
            $first = $this->series->firstValidated($user, $orientation);
            $best = $this->series->best($user, $orientation);
            $view->orientations[] = [
                'orientation' => $orientation->value,
                'validated' => null !== $first,
                'validatedAt' => $first?->getClosedAt()?->format(\DATE_ATOM),
                'seriesCount' => $this->series->countClosed($user, $orientation),
                'best' => null !== $best ? self::series($best) : null,
            ];
        }
        $view->history = array_map(self::series(...), $this->series->closedOf($user, self::HISTORY_SIZE));

        return $view;
    }

    /**
     * @return SeriesView
     */
    private static function series(Series $series): array
    {
        $count = $series->getAnswerCount();

        return [
            'id' => $series->getId()->toRfc4122(),
            'runId' => $series->getRun()->getId()->toRfc4122(),
            'orientation' => $series->getOrientation()->value,
            'answerCount' => $count,
            'successCount' => $series->getSuccessCount(),
            'successRate' => $count > 0 ? round($series->getSuccessCount() / $count, 4) : null,
            'validated' => $series->isValidated(),
            'startedAt' => $series->getStartedAt()->format(\DATE_ATOM),
            'closedAt' => $series->getClosedAt()?->format(\DATE_ATOM),
        ];
    }
}
