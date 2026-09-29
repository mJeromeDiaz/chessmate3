<?php

declare(strict_types=1);

namespace App\ApiResource\Woodpecker;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\Entity\Woodpecker\Cycle;
use App\Entity\Woodpecker\Set as SetEntity;
use App\State\Woodpecker\CreateSetProcessor;
use App\State\Woodpecker\SetActionProcessor;
use App\State\Woodpecker\SetProvider;
use App\Woodpecker\Stats\CycleStats;

/**
 * A Woodpecker set of the current user, with its cycle runs and their statistics. Every query
 * filters on the authenticated user: another user's set answers 404.
 */
#[ApiResource(
    shortName: 'WoodpeckerSet',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new GetCollection(
            uriTemplate: '/woodpecker/sets',
            paginationEnabled: false,
            openapi: new Operation(parameters: [new Parameter('archived', 'query', 'true: archived sets only', schema: ['type' => 'boolean'])]),
            provider: SetProvider::class,
        ),
        new Get(uriTemplate: '/woodpecker/sets/{id}', requirements: ['id' => self::UUID_PATTERN], provider: SetProvider::class),
        new Post(uriTemplate: '/woodpecker/sets', input: CreateSetInput::class, processor: CreateSetProcessor::class),
        new Post(uriTemplate: '/woodpecker/sets/{id}/pause', requirements: ['id' => self::UUID_PATTERN], status: 200, input: false, read: false, name: 'woodpecker_set_pause', processor: SetActionProcessor::class),
        new Post(uriTemplate: '/woodpecker/sets/{id}/resume', requirements: ['id' => self::UUID_PATTERN], status: 200, input: false, read: false, name: 'woodpecker_set_resume', processor: SetActionProcessor::class),
        new Post(uriTemplate: '/woodpecker/sets/{id}/abandon', requirements: ['id' => self::UUID_PATTERN], status: 200, input: false, read: false, name: 'woodpecker_set_abandon', processor: SetActionProcessor::class),
        new Post(uriTemplate: '/woodpecker/sets/{id}/archive', requirements: ['id' => self::UUID_PATTERN], status: 200, input: false, read: false, name: 'woodpecker_set_archive', processor: SetActionProcessor::class),
    ],
)]
final class Set
{
    public const UUID_PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    #[ApiProperty(identifier: true)]
    public string $id;
    public string $name;
    /** classic or light */
    public string $mode;
    /** active, paused, completed or abandoned */
    public string $status;
    public bool $archived;
    public int $puzzleCount;
    public int $ratingMin;
    public int $ratingMax;
    /** @var list<string> */
    public array $themes;
    public int $cycleCount;
    public int $firstCycleDays;
    public float $reductionFactor;
    public int $minCycleDays;
    public int $restDays;
    public bool $shuffle;
    public \DateTimeImmutable $createdAt;
    public ?\DateTimeImmutable $pausedAt;
    public ?\DateTimeImmutable $completedAt;
    public ?\DateTimeImmutable $abandonedAt;
    /** The user's IANA timezone, in which deadlines are local day ends. */
    public string $timezone;
    /** The open run (resting or active), if any. */
    #[ApiProperty(genId: false)]
    public ?CycleView $current;
    /** @var list<CycleView> every run, by cycle number then run */
    #[ApiProperty(genId: false)]
    public array $cycles;

    /**
     * @param list<Cycle>               $cycles
     * @param array<string, CycleStats> $stats  by cycle id
     */
    public static function from(SetEntity $set, array $cycles, array $stats, \DateTimeImmutable $now): self
    {
        $config = $set->getConfig();
        $timezone = $set->getUser()->getDateTimeZone();
        $view = new self();
        $view->id = $set->getId()->toRfc4122();
        $view->name = $set->getName();
        $view->mode = $set->getMode()->value;
        $view->status = $set->getStatus()->value;
        $view->archived = $set->isArchived();
        $view->puzzleCount = $config->puzzleCount;
        $view->ratingMin = $config->ratingMin;
        $view->ratingMax = $config->ratingMax;
        $view->themes = $config->themes;
        $view->cycleCount = $config->cycleCount;
        $view->firstCycleDays = $config->firstCycleDays;
        $view->reductionFactor = $config->reductionFactor;
        $view->minCycleDays = $config->minCycleDays;
        $view->restDays = $config->restDays;
        $view->shuffle = $config->shuffle;
        $view->createdAt = $set->getCreatedAt();
        $view->pausedAt = $set->getPausedAt();
        $view->completedAt = $set->getCompletedAt();
        $view->abandonedAt = $set->getAbandonedAt();
        $view->timezone = $timezone->getName();
        $view->cycles = [];
        $view->current = null;
        foreach ($cycles as $cycle) {
            $cycleView = CycleView::from($cycle, $stats[$cycle->getId()->toRfc4122()] ?? null, $now, $timezone);
            $view->cycles[] = $cycleView;
            if ($cycle->isOpen()) {
                $view->current = $cycleView;
            }
        }

        return $view;
    }
}
