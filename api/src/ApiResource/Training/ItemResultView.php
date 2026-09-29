<?php

declare(strict_types=1);

namespace App\ApiResource\Training;

use App\Training\Module\ItemResult;

final class ItemResultView
{
    public string $itemId;
    public bool $success;
    /** @var array<string, mixed> */
    public array $data;

    public static function from(ItemResult $result): self
    {
        $view = new self();
        $view->itemId = $result->itemId;
        $view->success = $result->success;
        $view->data = $result->data;

        return $view;
    }
}
