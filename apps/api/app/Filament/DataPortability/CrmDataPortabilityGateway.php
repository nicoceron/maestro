<?php

namespace App\Filament\DataPortability;

use App\Models\Studio;
use App\Models\User;
use Illuminate\Container\Attributes\Bind;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

#[Bind(LaravelCrmDataPortabilityGateway::class)]
interface CrmDataPortabilityGateway
{
    public function templateDownloadUrl(Studio $studio, User $actor): string;

    public function stageImport(
        Studio $studio,
        User $actor,
        TemporaryUploadedFile $file,
        string $idempotencyKey,
    ): CrmImportWorkspace;

    /** @param array<string, string> $mapping */
    public function previewImport(
        Studio $studio,
        User $actor,
        string $importId,
        int $expectedVersion,
        array $mapping,
    ): CrmImportWorkspace;

    /** @param array<string, array{decision:string,candidate_id?:string,household_candidate_id?:string}> $resolutions */
    public function resolveDuplicates(
        Studio $studio,
        User $actor,
        string $importId,
        int $expectedVersion,
        array $resolutions,
    ): CrmImportWorkspace;

    public function commitImport(
        Studio $studio,
        User $actor,
        string $importId,
        int $expectedVersion,
        string $idempotencyKey,
    ): CrmImportWorkspace;

    public function resumeImport(
        Studio $studio,
        User $actor,
        string $importId,
        int $expectedVersion,
        string $idempotencyKey,
    ): CrmImportWorkspace;

    public function importWorkspace(Studio $studio, User $actor, string $importId): CrmImportWorkspace;

    public function requestExport(
        Studio $studio,
        User $actor,
        string $idempotencyKey,
    ): CrmExportWorkspace;

    public function exportWorkspace(Studio $studio, User $actor, string $exportId): CrmExportWorkspace;
}
