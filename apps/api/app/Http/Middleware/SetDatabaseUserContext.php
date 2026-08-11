<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Tenancy\RequestDatabaseContext;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SetDatabaseUserContext
{
    public function __construct(private readonly RequestDatabaseContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user() ?? Filament::auth()->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $this->context->activateUser($user);

        try {
            return $next($request);
        } finally {
            $this->context->clearUser();
        }
    }
}
