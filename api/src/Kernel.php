<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        // Every timestamp is created, stored and compared in UTC (docs/ACTIVITY.md): never depend
        // on the server's php.ini. Local dates are always computed explicitly from the user's
        // IANA timezone.
        date_default_timezone_set('UTC');

        parent::boot();
    }
}
