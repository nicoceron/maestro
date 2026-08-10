<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureActiveStudioMembership
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();

        if ($tenant !== null && ! $request->user()?->canAccessTenant($tenant)) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
