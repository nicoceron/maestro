<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Invitations\CreateStudioInvitation;
use App\Actions\Invitations\RevokeStudioInvitation;
use App\Enums\MembershipRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreStudioInvitationRequest;
use App\Http\Resources\StudioInvitationResource;
use App\Models\Studio;
use App\Models\StudioInvitation;
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

        return StudioInvitationResource::collection(
            $studio->invitations()->latest()->paginate(25),
        );
    }

    public function store(
        StoreStudioInvitationRequest $request,
        Studio $studio,
        CreateStudioInvitation $createInvitation,
    ): JsonResponse {
        Gate::authorize('create', [StudioInvitation::class, $studio]);
        $role = MembershipRole::from((string) $request->validated('role'));
        Gate::authorize('invite', [StudioInvitation::class, $studio, $role]);

        $result = $createInvitation->handle(
            $studio,
            $request->user(),
            (string) $request->validated('email'),
            $role,
        );

        return (new StudioInvitationResource($result['invitation']))
            ->additional(['message' => 'Invitation sent.'])
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
        $revokeInvitation->handle($invitation);

        return response()->noContent();
    }
}
