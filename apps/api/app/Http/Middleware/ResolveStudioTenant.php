<?php

namespace App\Http\Middleware;

use App\Enums\MembershipStatus;
use App\Enums\StudioStatus;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveStudioTenant
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next, string $suspendedAccess = 'deny'): Response
    {
        $studio = $request->route('studio');

        abort_unless($studio instanceof Studio, Response::HTTP_NOT_FOUND);
        abort_if(
            $suspendedAccess !== 'allow'
                && in_array($studio->status, [StudioStatus::Suspended, StudioStatus::Closed], true),
            Response::HTTP_FORBIDDEN,
        );

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
