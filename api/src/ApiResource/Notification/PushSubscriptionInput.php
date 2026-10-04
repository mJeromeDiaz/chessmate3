<?php

declare(strict_types=1);

namespace App\ApiResource\Notification;

use App\Notification\Push\EndpointPolicy;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A browser's PushSubscription (`subscription.toJSON()`): endpoint and keys.
 */
final class PushSubscriptionInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: EndpointPolicy::MAX_LENGTH)]
    public string $endpoint = '';

    /** @var array{p256dh?: string, auth?: string} */
    #[Assert\Collection(fields: [
        'p256dh' => [new Assert\Type('string'), new Assert\Length(max: 128)],
        'auth' => [new Assert\Type('string'), new Assert\Length(max: 64)],
    ], allowExtraFields: false, allowMissingFields: true)]
    public array $keys = [];
}
