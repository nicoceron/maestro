<?php

return [
    'token_key' => env('SUPPORT_ACCESS_TOKEN_KEY', env('APP_KEY')),
    'grant_max_minutes' => 240,
    'session_max_minutes' => 120,
    'recent_auth_seconds' => 600,
];
