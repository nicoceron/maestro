<?php

namespace App\DataLifecycle\Http\Controllers;

use App\DataLifecycle\Actions\ApproveTenantDeletion;
use App\DataLifecycle\Actions\CancelTenantDeletion;
use App\DataLifecycle\Actions\RequestTenantDeletion;
use App\DataLifecycle\Actions\RestoreTenantDeletion;
use App\DataLifecycle\Http\Requests\RequestTenantDeletionRequest;
use App\DataLifecycle\Http\Resources\TenantDeletionRequestResource;
use App\DataLifecycle\Models\TenantDeletionRequest;
use App\Models\Studio;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class TenantDeletionController
{
    public function index(Studio $studio): AnonymousResourceCollection
    {
        Gate::authorize('create', [TenantDeletionRequest::class, $studio]);

        return TenantDeletionRequestResource::collection(
            TenantDeletionRequest::query()->where('studio_id', $studio->getKey())->latest()->paginate(25),
        );
    }

    public function store(
        RequestTenantDeletionRequest $request,
        Studio $studio,
        RequestTenantDeletion $action,
    ): JsonResponse {
        Gate::authorize('create', [TenantDeletionRequest::class, $studio]);
        try {
            $deletion = $action->handle(
                $studio,
                $request->user(),
                $request->string('reason')->toString(),
                $request->string('confirmation_phrase')->toString(),
                $request->string('idempotency_key')->toString(),
            );
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        return (new TenantDeletionRequestResource($deletion))->response()->setStatusCode(Response::HTTP_ACCEPTED);
    }

    public function show(Studio $studio, string $deletion): TenantDeletionRequestResource
    {
        $model = $this->deletion($studio, $deletion);
        Gate::authorize('view', $model);

        return new TenantDeletionRequestResource($model);
    }

    public function approve(Studio $studio, string $deletion, ApproveTenantDeletion $action): TenantDeletionRequestResource|JsonResponse
    {
        $model = $this->deletion($studio, $deletion);
        Gate::authorize('approve', $model);
        try {
            return new TenantDeletionRequestResource($action->handle($model, auth()->user()));
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }
    }

    public function cancel(Studio $studio, string $deletion, CancelTenantDeletion $action): TenantDeletionRequestResource|JsonResponse
    {
        $model = $this->deletion($studio, $deletion);
        Gate::authorize('cancel', $model);
        try {
            return new TenantDeletionRequestResource($action->handle($model, auth()->user()));
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }
    }

    public function restore(Studio $studio, string $deletion, RestoreTenantDeletion $action): TenantDeletionRequestResource|JsonResponse
    {
        $model = $this->deletion($studio, $deletion);
        Gate::authorize('restore', $model);
        try {
            return new TenantDeletionRequestResource($action->handle($model, auth()->user()));
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }
    }

    private function deletion(Studio $studio, string $id): TenantDeletionRequest
    {
        return TenantDeletionRequest::query()->where('studio_id', $studio->getKey())->whereKey($id)->firstOrFail();
    }

    private function domainError(DomainException $exception): JsonResponse
    {
        return response()->json([
            'message' => 'The tenant deletion lifecycle operation could not be completed.',
            'code' => $exception->getMessage(),
        ], Response::HTTP_CONFLICT);
    }
}
