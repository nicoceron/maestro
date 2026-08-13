<?php

namespace App\TenantData\Http\Controllers;

use App\Models\Studio;
use App\TenantData\Actions\RequestTenantDataExport;
use App\TenantData\Enums\TenantDataExportStatus;
use App\TenantData\Http\Requests\RequestTenantExportRequest;
use App\TenantData\Http\Resources\TenantDataExportResource;
use App\TenantData\Models\TenantDataExport;
use App\TenantData\Support\TenantExportBuilder;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

final class TenantDataExportController
{
    public function index(Studio $studio): AnonymousResourceCollection
    {
        Gate::authorize('create', [TenantDataExport::class, $studio]);

        return TenantDataExportResource::collection(
            TenantDataExport::query()->where('studio_id', $studio->getKey())->latest()->paginate(25),
        );
    }

    public function store(
        RequestTenantExportRequest $request,
        Studio $studio,
        RequestTenantDataExport $action,
    ): JsonResponse {
        Gate::authorize('create', [TenantDataExport::class, $studio]);

        try {
            $export = $action->handle(
                $studio,
                $request->user(),
                $request->string('idempotency_key')->toString(),
                $request->boolean('include_media_inventory'),
            );
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        }

        return (new TenantDataExportResource($export))->response()->setStatusCode(Response::HTTP_ACCEPTED);
    }

    public function show(Studio $studio, string $export): TenantDataExportResource
    {
        $model = $this->export($studio, $export);
        Gate::authorize('view', $model);

        return new TenantDataExportResource($model);
    }

    public function downloadUrl(Studio $studio, string $export): JsonResponse
    {
        $model = $this->export($studio, $export);
        Gate::authorize('download', $model);
        abort_unless(
            $model->status === TenantDataExportStatus::Ready && $model->expires_at?->isFuture(),
            Response::HTTP_GONE,
            'The export is not available.',
        );

        $expiresAt = now()->addMinutes((int) config('tenant-data.download_ttl_minutes', 5));

        return response()->json([
            'data' => [
                'url' => URL::temporarySignedRoute(
                    'api.v1.tenant-data-exports.download',
                    $expiresAt,
                    ['studio' => $studio, 'export' => $model->getKey()],
                ),
                'expires_at' => $expiresAt->toIso8601String(),
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function download(
        Request $request,
        Studio $studio,
        string $export,
        TenantExportBuilder $builder,
    ): Response {
        $model = $this->export($studio, $export);
        Gate::authorize('download', $model);
        abort_unless(
            $request->hasValidSignature()
                && $model->status === TenantDataExportStatus::Ready
                && $model->expires_at?->isFuture(),
            Response::HTTP_GONE,
            'The export is not available.',
        );
        $archive = $builder->decryptedArchive($model);
        $claimed = TenantDataExport::query()
            ->whereKey($model->getKey())
            ->where('download_count', 0)
            ->where('status', TenantDataExportStatus::Ready->value)
            ->where('expires_at', '>', now())
            ->update([
                'download_count' => 1,
                'last_downloaded_at' => now(),
            ]);
        abort_unless($claimed === 1, Response::HTTP_GONE, 'This one-use download has already been claimed.');

        return response($archive, Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.maestro.tenant-export+json',
            'Content-Disposition' => 'attachment; filename="maestro-'.$studio->slug.'-'.$model->getKey().'.json"',
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function export(Studio $studio, string $id): TenantDataExport
    {
        return TenantDataExport::query()
            ->where('studio_id', $studio->getKey())
            ->whereKey($id)
            ->firstOrFail();
    }

    private function domainError(DomainException $exception): JsonResponse
    {
        return response()->json([
            'message' => 'The tenant data operation could not be completed.',
            'code' => $exception->getMessage(),
        ], Response::HTTP_CONFLICT);
    }
}
