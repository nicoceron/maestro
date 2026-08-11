<?php

namespace App\Http\Middleware;

use App\Enums\MembershipStatus;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Support\Tenancy\TenantContext;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureActiveStudioMembership
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();

        if ($tenant === null) {
            return $next($request);
        }

        abort_unless($tenant instanceof Studio, Response::HTTP_FORBIDDEN);

        $membership = StudioMembership::query()
            ->where('studio_id', $tenant->getKey())
            ->where('user_id', $request->user()?->getAuthIdentifier())
            ->where('status', MembershipStatus::Active)
            ->first();

        abort_unless($membership !== null, Response::HTTP_FORBIDDEN);

        $this->tenantContext->activate($tenant, $membership);

        try {
            return $next($request);
        } finally {
            $this->tenantContext->clear();
        }
    }
}
