<?php

declare(strict_types=1);

namespace App\Shop\Purchase;

/**
 * Why a purchase was refused (docs/SHOP.md).
 */
final class PurchaseRefusal extends \RuntimeException
{
    public const NOT_FOUND = 'not_found';
    public const NOT_ON_SALE = 'not_on_sale';
    public const INSUFFICIENT = 'insufficient';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
