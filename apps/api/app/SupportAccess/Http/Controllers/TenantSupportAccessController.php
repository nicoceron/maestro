<?php

namespace App\SupportAccess\Http\Controllers;

use App\Models\Studio;
use App\Models\User;
use App\SupportAccess\Actions\ApproveSupportAccess;
use App\SupportAccess\Actions\RejectSupportAccess;
use App\SupportAccess\Actions\RevokeSupportAccess;
use App\SupportAccess\Http\Requests\DecideSupportAccessRequest;
use App\SupportAccess\Http\Resources\SupportAccessGrantResource;
use App\SupportAccess\Models\SupportAccessGrant;
use App\SupportAccess\SupportAccessPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class TenantSupportAccessController
{
    public function index(Request $request, Studio $studio, SupportAccessPolicy $policy): AnonymousResourceCollection
    {
        $user = $request->user();
        abort_unless($user instanceof User && $policy->manageStudio($user, $studio), 403);

        return SupportAccessGrantResource::collection(SupportAccessGrant::query()
            ->with(['requester', 'approver'])->where('studio_id', $studio->getKey())
            ->orderByDesc('created_at')->cursorPaginate(50));
    }

    public function approve(
        DecideSupportAccessRequest $request,
        Studio $studio,
        SupportAccessGrant $grant,
        ApproveSupportAccess $approve,
    ): SupportAccessGrantResource {
        $this->assertStudio($studio, $grant);

        return new SupportAccessGrantResource($approve->handle($request->user(), $grant, (int) $request->validated('version'))->load(['requester', 'approver']));
    }

    public function reject(
        DecideSupportAccessRequest $request,
        Studio $studio,
        SupportAccessGrant $grant,
        RejectSupportAccess $reject,
    ): SupportAccessGrantResource {
        $this->assertStudio($studio, $grant);

        return new SupportAccessGrantResource($reject->handle(
            $request->user(), $grant, (int) $request->validated('version'), (string) $request->validated('reason'),
        )->load(['requester', 'approver']));
    }

    public function revoke(
        DecideSupportAccessRequest $request,
        Studio $studio,
        SupportAccessGrant $grant,
        RevokeSupportAccess $revoke,
    ): SupportAccessGrantResource {
        $this->assertStudio($studio, $grant);

        return new SupportAccessGrantResource($revoke->handle(
            $request->user(), $grant, (int) $request->validated('version'), (string) $request->validated('reason'),
        )->load(['requester', 'approver']));
    }

    private function assertStudio(Studio $studio, SupportAccessGrant $grant): void
    {
        abort_unless($grant->studio_id === $studio->getKey(), 404);
    }
}
