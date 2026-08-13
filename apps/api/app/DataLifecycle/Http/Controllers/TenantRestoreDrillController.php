<?php

namespace App\DataLifecycle\Http\Controllers;

use App\DataLifecycle\Actions\RequestTenantRestoreDrill;
use App\DataLifecycle\Http\Resources\TenantRestoreDrillResource;
use App\DataLifecycle\Models\TenantRestoreDrill;
use App\Models\Studio;
use App\TenantData\Models\TenantDataExport;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class TenantRestoreDrillController
{
    public function store(
        Studio $studio,
        string $export,
        RequestTenantRestoreDrill $action,
    ): JsonResponse {
        $model = TenantDataExport::query()->where('studio_id', $studio->getKey())->whereKey($export)->firstOrFail();
        Gate::authorize('runRestoreDrill', $model);
        try {
            return (new TenantRestoreDrillResource($action->handle($model, auth()->user())))
                ->response()
                ->setStatusCode(Response::HTTP_ACCEPTED);
        } catch (DomainException $exception) {
            return response()->json([
                'message' => 'The restore drill could not be started.',
                'code' => $exception->getMessage(),
            ], Response::HTTP_CONFLICT);
        }
    }

    public function show(Studio $studio, string $drill): TenantRestoreDrillResource
    {
        $model = TenantRestoreDrill::query()->where('studio_id', $studio->getKey())->whereKey($drill)->firstOrFail();
        Gate::authorize('view', $model);

        return new TenantRestoreDrillResource($model);
    }
}
