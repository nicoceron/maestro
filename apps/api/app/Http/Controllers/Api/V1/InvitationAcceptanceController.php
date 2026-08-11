<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Invitations\AcceptStudioInvitation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\InvitationTokenRequest;
use App\Http\Resources\StudioResource;
use App\Models\StudioInvitation;
use App\Support\Tenancy\RequestDatabaseContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class InvitationAcceptanceController extends Controller
{
    public function show(
        InvitationTokenRequest $request,
        RequestDatabaseContext $databaseContext,
    ): JsonResponse {
        $token = (string) $request->validated('invitation_token');
        $invitation = DB::transaction(function () use ($databaseContext, $token): ?StudioInvitation {
            $databaseContext->activateInvitationToken($token);

            return StudioInvitation::query()
                ->where('token_hash', hash('sha256', $token))
                ->first();
        });

        abort_unless($invitation?->isPending(), Response::HTTP_NOT_FOUND, 'Invitation unavailable.');

        return response()->json([
            'data' => [
                'status' => 'pending',
                'email_hint' => $this->emailHint($invitation->email_normalized),
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ],
        ])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }

    public function accept(
        InvitationTokenRequest $request,
        AcceptStudioInvitation $acceptInvitation,
    ): JsonResponse {
        $token = (string) $request->validated('invitation_token');
        $membership = $acceptInvitation->handle($request->user(), $token);
        $studio = $membership->studio;
        $studio->setRelation('pivot', $membership);

        return (new StudioResource($studio))
            ->additional(['message' => 'Invitation accepted.'])
            ->response();
    }

    private function emailHint(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).str_repeat('•', max(3, mb_strlen($local) - 1)).'@'.$domain;
    }
}
