<?php

$trustedHosts = array_values(array_filter(array_map(
    static fn (string $host): string => '^'.preg_quote(trim($host), '/').'$',
    explode(',', (string) env('TRUSTED_HOSTS', 'localhost,127.0.0.1')),
)));

$requestOrigins = array_values(array_unique(array_filter([
    rtrim((string) env('APP_URL', 'http://localhost:8000'), '/'),
    rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/'),
    ...array_map(
        static fn (string $origin): string => rtrim(trim($origin), '/'),
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000')),
    ),
])));

return [
    'trusted_hosts' => $trustedHosts,
    'request_origins' => $requestOrigins,
    'password_breach_timeout_seconds' => (int) env('PASSWORD_BREACH_TIMEOUT_SECONDS', 2),
    'session_idle_minutes' => (int) env('SESSION_LIFETIME', 480),
    'session_absolute_minutes' => (int) env('SESSION_ABSOLUTE_LIFETIME_MINUTES', 43200),
    'session_max_idle_minutes' => 480,
    'session_max_absolute_minutes' => 43200,
    'two_factor_challenge_seconds' => (int) env('TWO_FACTOR_CHALLENGE_SECONDS', 300),
    'two_factor_setup_seconds' => (int) env('TWO_FACTOR_SETUP_SECONDS', 600),
];
