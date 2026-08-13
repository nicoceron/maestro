<?php

namespace App\DataPortability\Http\Controllers;

use App\DataPortability\Actions\BuildCrmPortableExport;
use App\DataPortability\Actions\IssueCrmPortableExportDownloadUrl;
use App\DataPortability\Actions\PreviewCrmImport;
use App\DataPortability\Actions\RequestCrmImportCommit;
use App\DataPortability\Actions\ResolveCrmImportRows;
use App\DataPortability\Actions\StageCrmImport;
use App\DataPortability\Http\Requests\CommitCrmImportRequest;
use App\DataPortability\Http\Requests\ListCrmImportRowsRequest;
use App\DataPortability\Http\Requests\PreviewCrmImportRequest;
use App\DataPortability\Http\Requests\ResolveCrmImportRequest;
use App\DataPortability\Http\Requests\StageCrmImportRequest;
use App\DataPortability\Http\Resources\CrmImportBatchResource;
use App\DataPortability\Http\Resources\CrmPortableExportResource;
use App\DataPortability\Models\CrmImportBatch;
use App\DataPortability\Models\CrmImportRow;
use App\DataPortability\Models\CrmPortableExport;
use App\DataPortability\Support\CrmDataPortabilityConflict;
use App\DataPortability\Support\CrmPortableCsv;
use App\Http\Controllers\Controller;
use App\Models\Studio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

final class CrmDataPortabilityController extends Controller
{
    public function template(Studio $studio, CrmPortableCsv $csv): Response
    {
        Gate::authorize('create', [CrmImportBatch::class, $studio]);

        return response($csv->template(), 200, ['Content-Type' => 'text/csv; charset=utf-8; header=present', 'Content-Disposition' => 'attachment; filename="maestro-people-template.csv"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function storeImport(StageCrmImportRequest $request, Studio $studio, StageCrmImport $stage): JsonResponse
    {
        $key = $this->idempotency($request);
        $batch = $stage->handle($studio, $request->user(), $request->file('file'), $key);

        return (new CrmImportBatchResource($batch))->response()->setStatusCode(202);
    }

    public function showImport(Request $request, Studio $studio, string $import): CrmImportBatchResource
    {
        $batch = $this->batch($studio, $import);
        Gate::authorize('view', $batch);

        return new CrmImportBatchResource($batch);
    }

    public function preview(PreviewCrmImportRequest $request, Studio $studio, string $import, PreviewCrmImport $preview): JsonResponse
    {
        $data = $request->validated();
        $result = $preview->handle($studio, $request->user(), $this->batch($studio, $import), (int) $data['import_version'], $data['mapping'] ?? []);

        return response()->json(['data' => (new CrmImportBatchResource($result['batch']))->resolve($request), 'rows' => $this->rowPage($result['batch'], $request)]);
    }

    public function rows(ListCrmImportRowsRequest $request, Studio $studio, string $import): JsonResponse
    {
        $batch = $this->batch($studio, $import);
        Gate::authorize('view', $batch);

        return response()->json($this->rowPage($batch, $request));
    }

    public function resolve(ResolveCrmImportRequest $request, Studio $studio, string $import, ResolveCrmImportRows $resolve): CrmImportBatchResource
    {
        $data = $request->validated();

        return new CrmImportBatchResource($resolve->handle($studio, $request->user(), $this->batch($studio, $import), (int) $data['import_version'], $data['rows']));
    }

    public function commit(CommitCrmImportRequest $request, Studio $studio, string $import, RequestCrmImportCommit $commit): JsonResponse
    {
        return $this->requestCommit($request, $studio, $import, $commit, 'commit');
    }

    public function resume(CommitCrmImportRequest $request, Studio $studio, string $import, RequestCrmImportCommit $commit): JsonResponse
    {
        return $this->requestCommit($request, $studio, $import, $commit, 'resume');
    }

    public function errors(Request $request, Studio $studio, string $import, CrmPortableCsv $csv): Response
    {
        $batch = $this->batch($studio, $import);
        Gate::authorize('view', $batch);
        $rows = $batch->rows()->where('status', 'failed')->orderBy('row_number')->get()->map(fn (CrmImportRow $row) => [$row->row_number, $row->error_code, implode('|', $row->error_fields ?? [])])->all();

        return response($csv->encodeDataset(['row_number', 'error_code', 'fields'], $rows), 200, ['Content-Type' => 'text/csv; charset=utf-8; header=present', 'Content-Disposition' => 'attachment; filename="maestro-import-errors.csv"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function storeExport(Request $request, Studio $studio, BuildCrmPortableExport $builder): JsonResponse
    {
        $export = $builder->request($studio, $request->user(), $this->idempotency($request));

        return (new CrmPortableExportResource($export))->response()->setStatusCode(202);
    }

    public function showExport(Request $request, Studio $studio, string $export): CrmPortableExportResource
    {
        $record = $this->export($studio, $export);
        Gate::authorize('view', $record);

        return new CrmPortableExportResource($record);
    }

    public function downloadUrl(Request $request, Studio $studio, string $export, IssueCrmPortableExportDownloadUrl $issue): JsonResponse
    {
        return response()->json(['data' => $issue->handle($studio, $request->user(), $this->export($studio, $export))]);
    }

    public function download(Request $request, Studio $studio, string $export, BuildCrmPortableExport $builder): Response
    {
        $record = $this->export($studio, $export);
        Gate::authorize('download', $record);
        if (! ($record->status === 'ready' && $record->expires_at->isFuture() && $record->download_count === 0)) {
            throw new CrmDataPortabilityConflict('CRM_EXPORT_NOT_DOWNLOADABLE');
        }
        $bytes = DB::transaction(function () use ($studio, $record, $builder): string {
            $locked = CrmPortableExport::query()->where('studio_id', $studio->getKey())->where('requested_by_id', auth()->id())->lockForUpdate()->findOrFail($record->getKey());
            if (! ($locked->status === 'ready' && $locked->expires_at->isFuture() && $locked->download_count === 0)) {
                throw new CrmDataPortabilityConflict('CRM_EXPORT_ALREADY_DOWNLOADED');
            }
            $verified = $builder->decryptedArchive($locked);
            $locked->forceFill(['download_count' => 1, 'version' => $locked->version + 1])->save();

            return $verified;
        });

        return response($bytes, 200, ['Content-Type' => 'application/vnd.maestro.crm-portability+json', 'Content-Disposition' => 'attachment; filename="maestro-crm-'.$studio->slug.'.maestro"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function requestCommit(CommitCrmImportRequest $request, Studio $studio, string $import, RequestCrmImportCommit $commit, string $type): JsonResponse
    {
        $command = $commit->handle($studio, $request->user(), $this->batch($studio, $import), (int) $request->validated('import_version'), $this->idempotency($request), $type);

        return response()->json(['data' => ['command_id' => $command->getKey(), 'import_id' => $command->import_batch_id, 'status' => $command->status, 'type' => $command->type]], 202);
    }

    private function idempotency(Request $request): string
    {
        $key = $request->header('Idempotency-Key');
        abort_unless(is_string($key) && strlen($key) >= 8 && strlen($key) <= 128, 422, 'IDEMPOTENCY_KEY_REQUIRED');

        return $key;
    }

    private function batch(Studio $studio, string $id): CrmImportBatch
    {
        return CrmImportBatch::query()->where('studio_id', $studio->getKey())->where('requested_by_id', auth()->id())->whereNull('purged_at')->where('expires_at', '>', now())->findOrFail($id);
    }

    private function export(Studio $studio, string $id): CrmPortableExport
    {
        return CrmPortableExport::query()->where('studio_id', $studio->getKey())->where('requested_by_id', auth()->id())->whereNull('purged_at')->where('expires_at', '>', now())->findOrFail($id);
    }

    private function rowPage(CrmImportBatch $batch, Request $request): array
    {
        $status = $request->query('status');
        $paginator = $batch->rows()->when(is_string($status) && in_array($status, ['resolved', 'conflict', 'processing', 'created', 'updated', 'skipped', 'failed'], true), fn ($query) => $query->where('status', $status))->orderBy('row_number')->orderBy('id')->cursorPaginate(min(max((int) $request->query('per_page', 50), 1), 100));

        return ['data' => collect($paginator->items())->map(function (CrmImportRow $row): array {
            $payload = $row->normalized_payload ?? [];

            return ['id' => $row->getKey(), 'row_number' => $row->row_number, 'plan_version' => $row->plan_version, 'status' => $row->status, 'decision' => $row->decision, 'match_kind' => $row->match_kind,
                'preview' => array_intersect_key($payload, array_flip(['first_name', 'last_name', 'email', 'phone', 'birth_date', 'household_name', 'student_status'])),
                'candidates' => $row->status === 'conflict' ? $row->match_candidates : null,
                'error_code' => $row->error_code, 'error_fields' => $row->error_fields];
        })->all(), 'meta' => ['per_page' => $paginator->perPage(), 'next_cursor' => $paginator->nextCursor()?->encode(), 'previous_cursor' => $paginator->previousCursor()?->encode()]];
    }
}
