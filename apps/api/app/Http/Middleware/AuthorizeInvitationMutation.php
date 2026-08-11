<?php

namespace App\Http\Middleware;

use App\Enums\MembershipRole;
use App\Models\Studio;
use App\Models\StudioInvitation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authorizes invitation mutations before their sensitive quota is consumed.
 */
final class AuthorizeInvitationMutation
{
    public function handle(Request $request, Closure $next, string $mutation): Response
    {
        $studio = $request->route('studio');
        abort_unless($studio instanceof Studio, Response::HTTP_NOT_FOUND);

        match ($mutation) {
            'create' => $this->authorizeCreate($request, $studio),
            'resend' => $this->authorizeResend($request, $studio),
            default => throw new LogicException("Unsupported invitation mutation [{$mutation}]."),
        };

        return $next($request);
    }

    private function authorizeCreate(Request $request, Studio $studio): void
    {
        Gate::authorize('create', [StudioInvitation::class, $studio]);

        $validated = Validator::make($request->only('role'), [
            'role' => [
                'required',
                Rule::enum(MembershipRole::class)->only([
                    MembershipRole::Administrator,
                    MembershipRole::Office,
                    MembershipRole::Billing,
                    MembershipRole::Teacher,
                ]),
            ],
        ])->validate();
        $role = MembershipRole::from((string) $validated['role']);

        Gate::authorize('invite', [StudioInvitation::class, $studio, $role]);
    }

    private function authorizeResend(Request $request, Studio $studio): void
    {
        $invitation = $request->route('invitation');
        abort_unless(
            $invitation instanceof StudioInvitation
                && $invitation->studio_id === $studio->getKey(),
            Response::HTTP_NOT_FOUND,
        );
        Gate::authorize('resend', $invitation);
    }
}
