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
];
