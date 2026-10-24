<?php

declare(strict_types=1);

namespace App\ApiResource\Shop;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\NotExposed;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\ApiResource\Woodpecker\Set;
use App\State\Shop\DeclineDailyWheelProcessor;
use App\State\Shop\OpenDailyRewardProcessor;
use App\State\Shop\UnopenedDailyRewardsProvider;

/**
 * The daily chest (docs/SHOP.md, § 4 ter): a few crystals with the first exercise of the local day,
 * a streak badge's bonus the day it is won, credited when earned: opening the chest only marks it
 * seen (shown once). Another player's chest answers 404.
 *
 * @phpstan-import-type DailyView from \App\Shop\Reward\DailyRewarder
 */
#[ApiResource(
    shortName: 'ShopDailyReward',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Post(
            uriTemplate: '/shop/daily-rewards/{id}/open',
            requirements: ['id' => Set::UUID_PATTERN],
            status: 200,
            openapi: new Operation(summary: 'Marks a daily chest opened (idempotent), with the balance.'),
            input: false,
            read: false,
            deserialize: false,
            processor: OpenDailyRewardProcessor::class,
        ),
        new Post(
            uriTemplate: '/shop/daily-rewards/{id}/decline',
            requirements: ['id' => Set::UUID_PATTERN],
            status: 200,
            openapi: new Operation(summary: 'Declines the day\'s gift wheel (« Pas aujourd\'hui »): lost for that day.'),
            input: false,
            read: false,
            deserialize: false,
            processor: DeclineDailyWheelProcessor::class,
        ),
        new GetCollection(
            uriTemplate: '/shop/daily-rewards/unopened',
            paginationEnabled: false,
            openapi: new Operation(summary: 'The daily chests not seen yet, oldest first, and today\'s gift wheel still to spin.'),
            provider: UnopenedDailyRewardsProvider::class,
        ),
        // The items' IRI only: a daily chest is read through the list above.
        new NotExposed(uriTemplate: '/shop/daily-rewards/{id}', requirements: ['id' => Set::UUID_PATTERN]),
    ],
)]
final class DailyReward
{
    #[ApiProperty(identifier: true)]
    public string $id;
    /** The player's local day (Y-m-d). */
    public string $localDate;
    /** The streak reached that day. */
    public int $streak;
    /** The days of the streak badge won that day, null on other days. */
    public ?int $badgeDays;
    /** The daily draw (6 to 12), 0 since the gift wheel. */
    public int $dailyCrowns;
    /** The badge's bonus (0 without a badge). */
    public int $badgeCrowns;
    /** Both together. */
    public int $crowns;
    public bool $opened;
    /** The day's gift wheel: offered, spun, declined, off; null before the wheel existed. */
    public ?string $wheel;
    /** The wheel is still to spin (today's, offered): shown before the chest. */
    public bool $wheelOpen;
    /** The player's crowns now. */
    public int $balance;

    /**
     * @param DailyView $view
     */
    public static function of(array $view, int $balance): self
    {
        $reward = new self();
        $reward->id = $view['id'];
        $reward->localDate = $view['localDate'];
        $reward->streak = $view['streak'];
        $reward->badgeDays = $view['badgeDays'];
        $reward->dailyCrowns = $view['dailyCrowns'];
        $reward->badgeCrowns = $view['badgeCrowns'];
        $reward->crowns = $view['crowns'];
        $reward->opened = $view['opened'];
        $reward->wheel = $view['wheel'];
        $reward->wheelOpen = $view['wheelOpen'];
        $reward->balance = $balance;

        return $reward;
    }
}
