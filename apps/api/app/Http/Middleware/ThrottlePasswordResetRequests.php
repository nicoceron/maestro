<?php

namespace App\Http\Middleware;

use App\Http\Responses\GenericPasswordResetLinkResponse;
use App\Support\Auth\PasswordResetRateLimitKey;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ThrottlePasswordResetRequests
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly PasswordResetRateLimitKey $keys,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('password.email')) {
            return $next($request);
        }

        $accountKey = $this->keys->for(
            (string) $request->input('email'),
            (string) $request->ip(),
        );
        $ipKey = 'password-reset-ip|'.$request->ip();

        if ($this->limiter->tooManyAttempts($accountKey, 5)
            || $this->limiter->tooManyAttempts($ipKey, 30)) {
            return app(GenericPasswordResetLinkResponse::class)->toResponse($request);
        }

        $this->limiter->hit($accountKey, 60);
        $this->limiter->hit($ipKey, 60);

        return $next($request);
    }
}
