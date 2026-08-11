<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Auth\BrowserSessionRegistry;
use App\Support\Tenancy\RequestDatabaseContext;
use Closure;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforceBrowserSessionLifetime
{
    public function __construct(
        private readonly StatefulGuard $guard,
        private readonly BrowserSessionRegistry $sessions,
        private readonly RequestDatabaseContext $databaseContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || config('session.driver') !== 'database') {
            return $next($request);
        }

        $initialUser = $this->guard->user();
        $initialSessionId = $request->session()->getId();
        $contextActive = false;

        try {
            if ($initialUser instanceof User) {
                $this->databaseContext->activateUser($initialUser);
                $contextActive = true;

                if ($this->sessions->isExpired($request, $initialUser)) {
                    $this->sessions->revokeCurrent($request, $initialUser);
                    $this->guard->logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();

                    return new JsonResponse([
                        'message' => 'Your session has expired. Please sign in again.',
                    ], Response::HTTP_UNAUTHORIZED);
                }

                $this->sessions->touch($request, $initialUser);
            }

            $response = $next($request);
            $currentUser = $this->guard->user();

            if ($currentUser instanceof User) {
                $this->databaseContext->activateUser($currentUser);
                $contextActive = true;
                $this->sessions->touch($request, $currentUser);
            } elseif ($initialUser instanceof User) {
                $this->databaseContext->activateUser($initialUser);
                $contextActive = true;
                $this->sessions->revokeBySessionId($initialUser, $initialSessionId);
            }

            return $response;
        } finally {
            if ($contextActive) {
                $this->databaseContext->clearUser();
            }
        }
    }
}
