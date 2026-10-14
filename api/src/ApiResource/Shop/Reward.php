<?php

declare(strict_types=1);

namespace App\ApiResource\Shop;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\ApiResource\Woodpecker\Set;
use App\State\Shop\OpenRewardProcessor;

/**
 * The crowns a completed session earned (docs/SHOP.md, § 4 bis), credited when it closed: opening
 * its chest only marks it seen (shown once). Another player's reward answers 404.
 *
 * @phpstan-import-type RewardView from \App\Training\Session\SessionRewardInterface
 */
#[ApiResource(
    shortName: 'ShopReward',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Post(
            uriTemplate: '/shop/rewards/{id}/open',
            requirements: ['id' => Set::UUID_PATTERN],
            status: 200,
            openapi: new Operation(summary: 'Marks the chest of a session reward opened (idempotent), with the balance.'),
            input: false,
            read: false,
            deserialize: false,
            processor: OpenRewardProcessor::class,
        ),
    ],
)]
final class Reward
{
    #[ApiProperty(identifier: true)]
    public string $id;
    public int $crowns;
    /** Paid minutes, after the daily cap. */
    public int $paidMinutes;
    /** perfect, near or missed */
    public string $tier;
    /** The daily cap cut its paid time. */
    public bool $capped;
    public bool $opened;
    /** The player's crowns now. */
    public int $balance;

    /**
     * @param RewardView $view
     */
    public static function of(array $view, int $balance): self
    {
        $reward = new self();
        $reward->id = $view['id'];
        $reward->crowns = $view['crowns'];
        $reward->paidMinutes = $view['paidMinutes'];
        $reward->tier = $view['tier'];
        $reward->capped = $view['capped'];
        $reward->opened = $view['opened'];
        $reward->balance = $balance;

        return $reward;
    }
}
