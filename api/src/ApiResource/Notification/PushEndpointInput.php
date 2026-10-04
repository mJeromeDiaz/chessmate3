<?php

declare(strict_types=1);

namespace App\ApiResource\Notification;

use App\Notification\Push\EndpointPolicy;
use Symfony\Component\Validator\Constraints as Assert;

final class PushEndpointInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: EndpointPolicy::MAX_LENGTH)]
    public string $endpoint = '';
}
