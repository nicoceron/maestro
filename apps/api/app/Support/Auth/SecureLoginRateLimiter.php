<?php

namespace App\Support\Auth;

use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Laravel\Fortify\LoginRateLimiter;

final class SecureLoginRateLimiter extends LoginRateLimiter
{
    public function __construct(
        RateLimiter $limiter,
        private readonly LoginRateLimitKey $keys,
    ) {
        parent::__construct($limiter);
    }

    public function attempts(Request $request): int
    {
        return (int) $this->limiter->attempts($this->accountKey($request));
    }

    public function tooManyAttempts(Request $request): bool
    {
        return $this->limiter->tooManyAttempts($this->accountKey($request), 5)
            || $this->limiter->tooManyAttempts($this->ipKey($request), 30);
    }

    public function increment(Request $request): void
    {
        $this->limiter->hit($this->accountKey($request), 60);
        $this->limiter->hit($this->ipKey($request), 60);
    }

    public function availableIn(Request $request): int
    {
        return max(
            $this->limiter->availableIn($this->accountKey($request)),
            $this->limiter->availableIn($this->ipKey($request)),
        );
    }

    public function clear(Request $request): void
    {
        $this->limiter->clear($this->accountKey($request));
    }

    private function accountKey(Request $request): string
    {
        return $this->keys->for(
            (string) $request->input('email'),
            (string) $request->ip(),
        );
    }

    private function ipKey(Request $request): string
    {
        return 'login-ip|'.$request->ip();
    }
}
