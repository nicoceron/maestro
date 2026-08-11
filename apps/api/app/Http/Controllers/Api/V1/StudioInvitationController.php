<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Invitations\CreateStudioInvitation;
use App\Actions\Invitations\ResendStudioInvitation;
use App\Actions\Invitations\RevokeStudioInvitation;
use App\Enums\MembershipRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreStudioInvitationRequest;
use App\Http\Resources\StudioInvitationResource;
use App\Models\Studio;
use App\Models\StudioInvitation;
use App\Models\User;
use App\Policies\StudioInvitationPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class StudioInvitationController extends Controller
{
    public function index(Request $request, Studio $studio): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [StudioInvitation::class, $studio]);

        $filters = $request->validate([
            'status' => ['sometimes', 'string', 'in:pending,expired,accepted,revoked,superseded'],
            'role' => ['sometimes', 'string', 'in:administrator,office,billing,teacher'],
            'q' => ['sometimes', 'string', 'max:100'],
        ]);

        $query = $studio->invitations()->with(['latestDelivery', 'studio']);

        if (isset($filters['role'])) {
            $query->where('role', $filters['role']);
        }

        if (isset($filters['q'])) {
            $search = addcslashes(User::normalizeEmail($filters['q']), '\\%_');
            $query->where('email_normalized', 'like', "%{$search}%");
        }

        if (isset($filters['status'])) {
            $query->where(function ($query) use ($filters): void {
                match ($filters['status']) {
                    'accepted' => $query->whereNotNull('accepted_at'),
                    'revoked' => $query->whereNull('accepted_at')->whereNotNull('revoked_at'),
                    'superseded' => $query->whereNull('accepted_at')->whereNull('revoked_at')->whereNotNull('superseded_at'),
                    'expired' => $query->whereNull('accepted_at')->whereNull('revoked_at')->whereNull('superseded_at')->where('expires_at', '<=', now()),
                    'pending' => $query->whereNull('accepted_at')->whereNull('revoked_at')->whereNull('superseded_at')->where('expires_at', '>', now()),
                };
            });
        }

        /** @var StudioInvitationPolicy $policy */
        $policy = Gate::getPolicyFor(StudioInvitation::class);

        return StudioInvitationResource::collection(
            $query->orderByDesc('created_at')->orderByDesc('id')->paginate(25),
        )
            ->additional(['capabilities' => [
                'can_create' => Gate::allows('create', [StudioInvitation::class, $studio]),
                'invitable_roles' => $policy->invitableRoles($request->user(), $studio),
            ]]);
    }

    public function store(
        StoreStudioInvitationRequest $request,
        Studio $studio,
        CreateStudioInvitation $createInvitation,
    ): JsonResponse {
        Gate::authorize('create', [StudioInvitation::class, $studio]);
        $role = MembershipRole::from((string) $request->validated('role'));
        Gate::authorize('invite', [StudioInvitation::class, $studio, $role]);

        $invitation = $createInvitation->handle(
            $studio,
            $request->user(),
            (string) $request->validated('email'),
            $role,
        );

        return (new StudioInvitationResource($invitation))
            ->additional(['message' => 'Invitation queued.'])
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function destroy(
        Request $request,
        Studio $studio,
        StudioInvitation $invitation,
        RevokeStudioInvitation $revokeInvitation,
    ): Response {
        abort_unless($invitation->studio_id === $studio->getKey(), Response::HTTP_NOT_FOUND);
        Gate::authorize('revoke', $invitation);
        $revokeInvitation->handle($invitation, $request->user());

        return response()->noContent();
    }

    public function resend(
        Request $request,
        Studio $studio,
        StudioInvitation $invitation,
        ResendStudioInvitation $resendInvitation,
    ): JsonResponse {
        abort_unless($invitation->studio_id === $studio->getKey(), Response::HTTP_NOT_FOUND);
        Gate::authorize('resend', $invitation);
        $replacement = $resendInvitation->handle($invitation, $request->user());

        return (new StudioInvitationResource($replacement))
            ->additional(['message' => 'Invitation resend queued.'])
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }
}
