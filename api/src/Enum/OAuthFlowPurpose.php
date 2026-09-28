<?php

declare(strict_types=1);

namespace App\Enum;

enum OAuthFlowPurpose: string
{
    /** Sign in (or sign up) with the provider account. */
    case Login = 'login';

    /** Attach the provider account to the already signed-in user who started the flow. */
    case Link = 'link';
}
