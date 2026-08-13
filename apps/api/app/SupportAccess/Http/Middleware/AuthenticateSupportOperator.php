<?php

namespace App\SupportAccess\Http\Middleware;

use App\Models\User;
use App\SupportAccess\SupportAccessPolicy;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateSupportOperator
{
    public function __construct(private readonly SupportAccessPolicy $policy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();
        if (! $user instanceof User) {
            return redirect()->guest(Filament::getPanel('admin')->getLoginUrl());
        }
        abort_unless($user->two_factor_confirmed_at !== null && $this->policy->isOperator($user), 403);

        return $next($request);
    }
}
