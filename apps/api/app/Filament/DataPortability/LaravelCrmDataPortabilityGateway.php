<?php

namespace App\Filament\DataPortability;

use App\DataPortability\Actions\BuildCrmPortableExport;
use App\DataPortability\Actions\IssueCrmPortableExportDownloadUrl;
use App\DataPortability\Actions\PreviewCrmImport;
use App\DataPortability\Actions\RequestCrmImportCommit;
use App\DataPortability\Actions\ResolveCrmImportRows;
use App\DataPortability\Actions\StageCrmImport;
use App\DataPortability\Models\CrmImportBatch;
use App\DataPortability\Models\CrmImportRow;
use App\DataPortability\Models\CrmPortableExport;
use App\DataPortability\Support\CrmPortableCsv;
use App\Models\Studio;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

final readonly class LaravelCrmDataPortabilityGateway implements CrmDataPortabilityGateway
{
    public function __construct(
        private StageCrmImport $stage,
        private PreviewCrmImport $preview,
        private ResolveCrmImportRows $resolve,
        private RequestCrmImportCommit $commit,
        private BuildCrmPortableExport $export,
        private IssueCrmPortableExportDownloadUrl $downloadUrl,
    ) {}

    public function templateDownloadUrl(Studio $studio, User $actor): string
    {
        Gate::forUser($actor)->authorize('create', [CrmImportBatch::class, $studio]);

        return route('api.v1.data-portability.template', ['studio' => $studio->slug]);
    }

    public function stageImport(Studio $studio, User $actor, TemporaryUploadedFile $file, string $idempotencyKey): CrmImportWorkspace
    {
        return $this->workspace($this->stage->handle($studio, $actor, $file, $idempotencyKey));
    }

    public function previewImport(Studio $studio, User $actor, string $importId, int $expectedVersion, array $mapping): CrmImportWorkspace
    {
        $batch = $this->batch($studio, $actor, $importId);
        $result = $this->preview->handle($studio, $actor, $batch, $expectedVersion, $mapping);

        return $this->workspace($result['batch']);
    }

    public function resolveDuplicates(Studio $studio, User $actor, string $importId, int $expectedVersion, array $resolutions): CrmImportWorkspace
    {
        $batch = $this->batch($studio, $actor, $importId);
        $payload = [];
        foreach ($resolutions as $rowId => $resolution) {
            $row = CrmImportRow::query()
                ->where('studio_id', $studio->getKey())
                ->where('import_batch_id', $batch->getKey())
                ->where('status', 'conflict')
                ->findOrFail($rowId);
            $candidateId = $resolution['candidate_id'] ?? null;
            $candidate = collect($row->match_candidates['people'] ?? [])->first(
                fn (array $item): bool => (string) ($item['id'] ?? '') === $candidateId,
            );
            $householdCandidateId = $resolution['household_candidate_id'] ?? null;
            $householdCandidate = collect($row->match_candidates['households'] ?? [])->first(
                fn (array $item): bool => (string) ($item['id'] ?? '') === $householdCandidateId,
            );
            abort_if($householdCandidateId !== null && $householdCandidate === null, 422, 'Choose a household from the current duplicate plan.');
            $payload[] = array_filter([
                'row_id' => $rowId,
                'plan_version' => $row->plan_version,
                'decision' => $resolution['decision'],
                'candidate_person_id' => $resolution['decision'] === 'update' ? $candidateId : null,
                'candidate_person_version' => $resolution['decision'] === 'update' ? ($candidate['version'] ?? null) : null,
                'candidate_household_id' => $householdCandidateId,
                'candidate_household_version' => $householdCandidate['version'] ?? null,
            ], fn (mixed $value): bool => $value !== null);
        }

        $resolved = $this->resolve->handle($studio, $actor, $batch, $expectedVersion, $payload);

        return $this->workspace($resolved);
    }

    public function commitImport(Studio $studio, User $actor, string $importId, int $expectedVersion, string $idempotencyKey): CrmImportWorkspace
    {
        $batch = $this->batch($studio, $actor, $importId);
        $this->commit->handle($studio, $actor, $batch, $expectedVersion, $idempotencyKey, 'commit');

        return $this->workspace($batch->fresh(), status: 'queued');
    }

    public function resumeImport(Studio $studio, User $actor, string $importId, int $expectedVersion, string $idempotencyKey): CrmImportWorkspace
    {
        $batch = $this->batch($studio, $actor, $importId);
        $this->commit->handle($studio, $actor, $batch, $expectedVersion, $idempotencyKey, 'resume');

        return $this->workspace($batch->fresh(), status: 'queued');
    }

    public function importWorkspace(Studio $studio, User $actor, string $importId): CrmImportWorkspace
    {
        return $this->workspace($this->batch($studio, $actor, $importId));
    }

    public function requestExport(Studio $studio, User $actor, string $idempotencyKey): CrmExportWorkspace
    {
        return $this->exportView($this->export->request($studio, $actor, $idempotencyKey), $actor);
    }

    public function exportWorkspace(Studio $studio, User $actor, string $exportId): CrmExportWorkspace
    {
        $export = CrmPortableExport::query()
            ->where('studio_id', $studio->getKey())
            ->where('requested_by_id', $actor->getAuthIdentifier())
            ->findOrFail($exportId);
        Gate::forUser($actor)->authorize('view', $export);

        return $this->exportView($export, $actor);
    }

    private function batch(Studio $studio, User $actor, string $id): CrmImportBatch
    {
        $batch = CrmImportBatch::query()
            ->where('studio_id', $studio->getKey())
            ->where('requested_by_id', $actor->getAuthIdentifier())
            ->findOrFail($id);
        Gate::forUser($actor)->authorize('view', $batch);

        return $batch;
    }

    /** @param list<array<string, mixed>>|null $rows */
    private function workspace(CrmImportBatch $batch, ?array $rows = null, ?string $status = null): CrmImportWorkspace
    {
        $rows ??= $batch->rows()->where(function ($query): void {
            $query->where('status', 'conflict')->orWhere('status', 'failed');
        })->orderBy('row_number')->limit(200)->get()->map(fn (CrmImportRow $row): array => [
            'id' => $row->getKey(),
            'plan_version' => $row->plan_version,
            'row_number' => $row->row_number,
            'status' => $row->status,
            'decision' => $row->decision,
            'match_kind' => $row->match_kind,
            'candidate_person_id' => $row->candidate_person_id,
            'candidate_household_id' => $row->candidate_household_id,
            'preview' => array_intersect_key($row->normalized_payload ?? [], array_flip(['first_name', 'last_name', 'email'])),
            'candidates' => $row->match_candidates,
        ])->all();
        $duplicates = collect($rows)->where('status', 'conflict')->map(function (array $row): array {
            $people = collect($row['candidates']['people'] ?? [])->map(fn (array $candidate): array => [
                'id' => (string) $candidate['id'],
                'label' => (string) ($candidate['display_name'] ?? 'Matching person'),
                'version' => (int) $candidate['version'],
            ])->values()->all();
            $households = collect($row['candidates']['households'] ?? [])->map(fn (array $candidate): array => [
                'id' => (string) $candidate['id'],
                'label' => (string) ($candidate['name'] ?? 'Matching household'),
                'version' => (int) $candidate['version'],
            ])->values()->all();
            $preview = $row['preview'] ?? [];
            $label = trim(implode(' ', array_filter([$preview['first_name'] ?? null, $preview['last_name'] ?? null]))) ?: 'CSV row '.($row['row_number'] ?? '');

            return [
                'id' => (string) $row['id'],
                'plan_version' => (int) $row['plan_version'],
                'row' => (int) $row['row_number'],
                'label' => $label,
                'matched_to' => count($people) === 1 ? $people[0]['label'] : null,
                'reasons' => [str((string) ($row['match_kind'] ?? 'possible_duplicate'))->replace('_', ' ')->headline()->toString()],
                'resolution' => ($row['decision'] ?? 'conflict') === 'conflict' ? null : $row['decision'],
                'candidates' => $people,
                'selected_candidate_id' => $row['candidate_person_id'] ?? null,
                'household_candidates' => $households,
                'selected_household_candidate_id' => $row['candidate_household_id'] ?? null,
            ];
        })->values()->all();
        $storedMapping = collect($batch->column_mapping ?? [])->except('source_columns')
            ->filter(fn (mixed $value, mixed $key): bool => is_string($key) && is_string($value));
        $sourceColumns = (array) data_get($batch->column_mapping, 'source_columns', $storedMapping->values()->all());
        $mapping = $storedMapping->mapWithKeys(fn (string $source, string $canonical): array => [$source => $canonical])->all();

        return new CrmImportWorkspace(
            id: $batch->getKey(),
            version: $batch->version,
            status: $status ?? $batch->status,
            fileName: $batch->original_name,
            sourceColumns: array_values($sourceColumns),
            mappingTargets: collect(CrmPortableCsv::PEOPLE_COLUMNS)->mapWithKeys(fn (string $column): array => [$column => str($column)->replace('_', ' ')->headline()->toString()])->all(),
            mapping: $mapping,
            summary: [
                'total' => $batch->row_count,
                'valid' => max(0, $batch->row_count - $batch->conflicted_rows - $batch->failed_rows),
                'invalid' => $batch->failed_rows,
                'duplicates' => $batch->conflicted_rows,
                'creates' => $batch->created_rows,
                'updates' => $batch->updated_rows,
                'skips' => $batch->skipped_rows,
            ],
            duplicates: $duplicates,
            progress: [
                'total' => $batch->row_count,
                'processed' => $batch->processed_rows,
                'created' => $batch->created_rows,
                'updated' => $batch->updated_rows,
                'skipped' => $batch->skipped_rows,
                'failed' => $batch->failed_rows,
            ],
            canCommit: $batch->status === 'ready',
            canResume: $batch->status === 'completed_with_errors' && $batch->failed_rows > 0,
            errorReportUrl: $batch->failed_rows > 0 ? route('api.v1.data-portability.imports.errors', [
                'studio' => $batch->studio->slug,
                'import' => $batch->getKey(),
            ]) : null,
            completedAt: $batch->completed_at?->toIso8601String(),
        );
    }

    private function exportView(CrmPortableExport $export, User $actor): CrmExportWorkspace
    {
        $total = (int) collect($export->manifest['datasets'] ?? [])->sum('row_count');
        $downloadUrl = null;
        if ($export->status === 'ready' && $export->expires_at?->isFuture() && $export->download_count === 0) {
            $downloadUrl = $this->downloadUrl->handle($export->studio, $actor, $export)['url'];
        }

        return new CrmExportWorkspace(
            id: $export->getKey(),
            status: $export->status,
            progress: ['total' => $total, 'processed' => $export->status === 'ready' ? $total : 0],
            downloadUrl: $downloadUrl,
            expiresAt: $export->expires_at?->toIso8601String(),
            completedAt: $export->ready_at?->toIso8601String(),
        );
    }
}
