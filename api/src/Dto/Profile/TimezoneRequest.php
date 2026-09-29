<?php

declare(strict_types=1);

namespace App\Dto\Profile;

use Symfony\Component\Validator\Constraints as Assert;

final class TimezoneRequest
{
    /** IANA identifier, e.g. "Europe/Paris". */
    #[Assert\NotBlank]
    #[Assert\Timezone]
    public string $timezone = '';
}
