<?php

use App\TenantData\Listeners\RecordCurrentSessionMfaVerification;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;

return [
    ValidTwoFactorAuthenticationCodeProvided::class => [RecordCurrentSessionMfaVerification::class],
];
