<?php

declare(strict_types=1);

namespace App\State\Notification;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Notification\PushState;
use App\Notification\Push\PushSender;

/**
 * @implements ProviderInterface<PushState>
 */
final class PushStateProvider implements ProviderInterface
{
    public function __construct(
        private readonly PushSender $sender,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PushState
    {
        $state = new PushState();
        $state->enabled = $this->sender->isEnabled();
        $state->publicKey = $this->sender->publicKey();

        return $state;
    }
}
