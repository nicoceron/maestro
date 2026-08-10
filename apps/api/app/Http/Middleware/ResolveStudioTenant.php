<?php

namespace App\Http\Middleware;

use App\Enums\MembershipStatus;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveStudioTenant
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $studio = $request->route('studio');

        abort_unless($studio instanceof Studio, Response::HTTP_NOT_FOUND);

        $membership = StudioMembership::query()
            ->where('studio_id', $studio->getKey())
            ->where('user_id', $request->user()?->getAuthIdentifier())
            ->where('status', MembershipStatus::Active)
            ->first();

        abort_unless($membership !== null, Response::HTTP_FORBIDDEN);

        $this->tenantContext->activate($studio, $membership);

        try {
            return $next($request);
        } finally {
            $this->tenantContext->clear();
        }
    }
}
