<?php

declare(strict_types=1);

namespace App\ApiResource\Training;

use App\Training\Module\Item;

final class ItemView
{
    public string $id;
    /** e.g. woodpecker_puzzle: tells the client how to read `data` */
    public string $type;
    /** @var array<string, mixed> */
    public array $data;

    public static function from(Item $item): self
    {
        $view = new self();
        $view->id = $item->id;
        $view->type = $item->type;
        $view->data = $item->data;

        return $view;
    }
}
