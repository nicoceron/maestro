<?php

namespace App\SupportAccess\Http\Controllers;

use App\Models\Studio;
use App\Models\User;
use App\SupportAccess\Actions\EndSupportSession;
use App\SupportAccess\Actions\RequestSupportAccess;
use App\SupportAccess\Actions\StartSupportSession;
use App\SupportAccess\Http\Requests\RequestSupportAccessRequest;
use App\SupportAccess\Http\Resources\SupportAccessGrantResource;
use App\SupportAccess\Http\Resources\SupportSessionBannerResource;
use App\SupportAccess\Models\SupportAccessGrant;
use App\SupportAccess\Models\SupportAccessSession;
use App\SupportAccess\SupportAccessException;
use App\SupportAccess\SupportAccessPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class PlatformSupportAccessController
{
    public function index(Request $request, SupportAccessPolicy $policy): AnonymousResourceCollection
    {
        $user = $request->user();
        abort_unless($user instanceof User && $policy->isOperator($user), 403);

        return SupportAccessGrantResource::collection(SupportAccessGrant::query()
            ->with(['studio', 'requester', 'approver'])
            ->where('requested_by_user_id', $user->getAuthIdentifier())
            ->orderByDesc('created_at')->cursorPaginate(50));
    }

    public function store(RequestSupportAccessRequest $request, RequestSupportAccess $action): JsonResponse
    {
        $studio = Studio::query()->findOrFail($request->validated('studio_id'));
        $grant = $action->handle(
            $request->user(), $studio, $request->validated('scopes'), $request->validated('reason'),
            CarbonImmutable::parse($request->validated('starts_at')),
            CarbonImmutable::parse($request->validated('expires_at')),
            $request->idempotencyKey(),
        );

        return response()->json([
            'data' => (new SupportAccessGrantResource($grant->load(['studio', 'requester', 'approver'])))->resolve($request),
        ], 201);
    }

    public function start(Request $request, SupportAccessGrant $grant, StartSupportSession $start): JsonResponse
    {
        $recentAt = $this->recentAuthentication($request);
        $started = $start->handle($request->user(), $grant, $recentAt);
        $session = $started['session']->load(['studio', 'approver']);

        return response()->json([
            'data' => [
                'session' => (new SupportSessionBannerResource($session))->resolve($request),
                'access_token' => $started['access_token'],
                'token_type' => 'Maestro-Support-Session',
            ],
        ], 201)->header('Cache-Control', 'no-store, private');
    }

    public function end(Request $request, SupportAccessSession $session, EndSupportSession $end): SupportSessionBannerResource
    {
        return new SupportSessionBannerResource($end->handle($request->user(), $session)->load(['studio', 'approver']));
    }

    public function banner(Request $request, SupportAccessSession $session): SupportSessionBannerResource
    {
        /** @var SupportAccessSession $resolved */
        $resolved = $request->attributes->get('support_access_session');

        return new SupportSessionBannerResource($resolved);
    }

    private function recentAuthentication(Request $request): CarbonImmutable
    {
        $timestamp = $request->hasSession() ? $request->session()->get('auth.password_confirmed_at') : null;
        if (! is_int($timestamp) || $timestamp < 1) {
            throw new SupportAccessException('support_recent_auth_required', 'Recent authentication is required.', 423);
        }

        return CarbonImmutable::createFromTimestamp($timestamp);
    }
}
