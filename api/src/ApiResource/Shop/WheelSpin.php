<?php

declare(strict_types=1);

namespace App\ApiResource\Shop;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\Shop\Wheel\WheelSpinner;
use App\State\Shop\WheelSpinProcessor;

/**
 * A spin of the gift wheel (docs/SHOP.md, § 4 ter): the prize drawn by the server, already
 * credited. 409 `no_spin` (no wheel of the day to spin) or `no_ticket`.
 *
 * @phpstan-import-type SpinView from WheelSpinner
 */
#[ApiResource(
    shortName: 'ShopWheelSpin',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Post(
            uriTemplate: '/shop/wheel/spins',
            status: 200,
            openapi: new Operation(summary: 'Spins the gift wheel: the day\'s, or once more with a ticket.'),
            input: WheelSpinInput::class,
            read: false,
            processor: WheelSpinProcessor::class,
        ),
    ],
)]
final class WheelSpin
{
    #[ApiProperty(identifier: true)]
    public string $id;
    /** c5, c30, c300, gift or lose. */
    public string $prize;
    /** A gift's content: freeze or costume; null otherwise. */
    public ?string $gift;
    /** The crystals credited. */
    public int $crowns;
    /** Spun with a ticket. */
    public bool $ticket;
    /** The player's crystals now. */
    public int $balance;
    /** The tickets left. */
    public int $tickets;

    /**
     * @param SpinView $view
     */
    public static function of(array $view): self
    {
        $spin = new self();
        $spin->id = $view['id'];
        $spin->prize = $view['prize'];
        $spin->gift = $view['gift'];
        $spin->crowns = $view['crowns'];
        $spin->ticket = $view['ticket'];
        $spin->balance = $view['balance'];
        $spin->tickets = $view['tickets'];

        return $spin;
    }
}
