<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies a named Laravel rate limiter at its declared route-middleware position.
 *
 * Laravel prioritizes ThrottleRequests ahead of route binding and authorization.
 * This composition wrapper deliberately avoids that priority so sensitive quotas
 * are consumed only after the preceding authorization middleware succeeds.
 */
final class ApplyNamedRateLimiter
{
    public function __construct(private readonly ThrottleRequests $throttle) {}

    public function handle(Request $request, Closure $next, string $limiter): Response
    {
        return $this->throttle->handle($request, $next, $limiter);
    }
}
