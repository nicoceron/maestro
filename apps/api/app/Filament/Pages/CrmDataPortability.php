<?php

namespace App\Filament\Pages;

use App\DataPortability\Models\CrmImportBatch;
use App\DataPortability\Support\CrmDataPortabilityStepUp;
use App\Filament\DataPortability\CrmDataPortabilityGateway;
use App\Filament\DataPortability\CrmExportWorkspace;
use App\Filament\DataPortability\CrmImportWorkspace;
use App\Models\Studio;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpKernel\Exception\HttpException;
use UnitEnum;

final class CrmDataPortability extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?string $navigationLabel = 'Import & export';

    protected static ?string $title = 'CRM data portability';

    protected static ?string $slug = 'crm-data-portability';

    protected static ?int $navigationSort = 80;

    protected string $view = 'filament.pages.crm-data-portability';

    /** @var array<string, mixed>|null */
    public ?array $import = null;

    /** @var array<string, mixed>|null */
    public ?array $export = null;

    /** @var array<string, string|null> */
    public array $mapping = [];

    /** @var array<string, string> */
    public array $duplicateResolutions = [];

    /** @var array<string, string> */
    public array $duplicateCandidates = [];

    /** @var array<string, string> */
    public array $duplicateHouseholdCandidates = [];

    public static function canAccess(): bool
    {
        $studio = Filament::getTenant();
        $user = auth()->user();

        return $studio instanceof Studio
            && $user instanceof User
            && Gate::forUser($user)->allows('create', [CrmImportBatch::class, $studio]);
    }

    /** @return array<Action|ActionGroup> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadTemplate')
                ->label('Download CSV template')
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->url(fn (): string => $this->gateway()->templateDownloadUrl($this->studio(), $this->user()))
                ->openUrlInNewTab(false)
                ->visible(fn (): bool => $this->backendReady()),
            Action::make('uploadCsv')
                ->label('Upload CSV')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->modalHeading('Upload your people CSV')
                ->modalDescription('The file is staged privately. Nothing is changed until you review the dry run, resolve every duplicate, and explicitly commit.')
                ->schema([
                    FileUpload::make('file')
                        ->label('CSV file')
                        ->required()
                        ->storeFiles(false)
                        ->acceptedFileTypes([
                            'text/csv',
                            'text/plain',
                            'application/csv',
                            'application/vnd.ms-excel',
                        ])
                        ->maxSize(10 * 1024)
                        ->maxFiles(1)
                        ->uploadingMessage('Uploading CSV securely…')
                        ->helperText('CSV only, up to 10 MB. Spreadsheet formulas are treated as text and never executed.'),
                ])
                ->action(function (Action $action, array $data): void {
                    $this->authorizeWorkspace();
                    $this->requireRecentConfirmation('Confirm your identity and MFA again before staging CRM data.');
                    $file = $data['file'] ?? null;
                    if (! $file instanceof TemporaryUploadedFile) {
                        $action->halt();
                        throw ValidationException::withMessages(['file' => 'Choose one CSV file to continue.']);
                    }

                    $this->syncImport($this->gateway()->stageImport(
                        $this->studio(),
                        $this->user(),
                        $file,
                        (string) Str::uuid(),
                    ));
                    Notification::make()->success()->title('CSV staged privately')->body('Map the detected columns to continue.')->send();
                })
                ->visible(fn (): bool => $this->backendReady()),
            Action::make('requestExport')
                ->label('Create portable .maestro bundle')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->requiresConfirmation()
                ->modalDescription('Creates a tenant-scoped .maestro bundle with a manifest and deterministic CSV datasets. The private download is short-lived and contains no credentials or storage paths.')
                ->action(function (): void {
                    $this->authorizeWorkspace();
                    $this->requireRecentConfirmation('Confirm your identity again before exporting people data.');
                    $this->syncExport($this->gateway()->requestExport(
                        $this->studio(),
                        $this->user(),
                        (string) Str::uuid(),
                    ));
                    Notification::make()->success()->title('Portable bundle requested')->body('Progress and the private download will appear here.')->send();
                })
                ->visible(fn (): bool => $this->backendReady()),
        ];
    }

    public function previewImport(): void
    {
        $this->authorizeWorkspace();
        $importId = $this->importId();
        $targets = array_keys($this->import['mappingTargets'] ?? []);
        $mapping = collect($this->mapping)
            ->map(fn (mixed $target): ?string => is_string($target) && $target !== '' ? $target : null)
            ->filter()
            ->all();

        foreach ($mapping as $source => $target) {
            if (! in_array($target, $targets, true)) {
                throw ValidationException::withMessages(["mapping.{$source}" => 'Choose a supported destination field.']);
            }
        }
        if ($mapping === []) {
            throw ValidationException::withMessages(['mapping' => 'Map at least one source column before running the dry run.']);
        }

        $this->syncImport($this->gateway()->previewImport(
            $this->studio(),
            $this->user(),
            $importId,
            $this->importVersion(),
            $mapping,
        ));
        Notification::make()->success()->title('Dry run complete')->body('Review validation and duplicate decisions before committing.')->send();
    }

    public function setMapping(string $source, ?string $target): void
    {
        $this->authorizeWorkspace();
        abort_unless(in_array($source, $this->import['sourceColumns'] ?? [], true), 422);
        abort_unless($target === null || $target === '' || array_key_exists($target, $this->import['mappingTargets'] ?? []), 422);
        $this->mapping[$source] = filled($target) ? $target : null;
    }

    public function setDuplicateResolution(string $duplicateId, string $resolution): void
    {
        $this->authorizeWorkspace();
        $known = collect($this->import['duplicates'] ?? [])->contains(
            fn (array $duplicate): bool => (string) ($duplicate['id'] ?? '') === $duplicateId,
        );
        abort_unless($known && in_array($resolution, ['create', 'update', 'skip', 'conflict'], true), 422);
        $this->duplicateResolutions[$duplicateId] = $resolution;
    }

    public function setDuplicateCandidate(string $duplicateId, string $candidateId): void
    {
        $this->authorizeWorkspace();
        $duplicate = collect($this->import['duplicates'] ?? [])->first(
            fn (array $row): bool => (string) ($row['id'] ?? '') === $duplicateId,
        );
        abort_unless(is_array($duplicate), 422);
        $knownCandidate = collect($duplicate['candidates'] ?? [])->contains(
            fn (array $candidate): bool => (string) ($candidate['id'] ?? '') === $candidateId,
        );
        abort_unless($knownCandidate, 422);
        $this->duplicateCandidates[$duplicateId] = $candidateId;
    }

    public function setDuplicateHouseholdCandidate(string $duplicateId, string $candidateId): void
    {
        $this->authorizeWorkspace();
        $duplicate = collect($this->import['duplicates'] ?? [])->first(
            fn (array $row): bool => (string) ($row['id'] ?? '') === $duplicateId,
        );
        abort_unless(is_array($duplicate), 422);
        $knownCandidate = collect($duplicate['household_candidates'] ?? [])->contains(
            fn (array $candidate): bool => (string) ($candidate['id'] ?? '') === $candidateId,
        );
        abort_unless($knownCandidate, 422);
        $this->duplicateHouseholdCandidates[$duplicateId] = $candidateId;
    }

    public function applyDuplicateResolution(string $resolution): void
    {
        $this->authorizeWorkspace();
        abort_unless(in_array($resolution, ['create', 'update', 'skip'], true), 422);
        foreach ($this->import['duplicates'] ?? [] as $duplicate) {
            $this->duplicateResolutions[(string) $duplicate['id']] = $resolution;
        }
    }

    public function saveDuplicateResolutions(): void
    {
        $this->authorizeWorkspace();
        $this->requireRecentConfirmation('Confirm your identity and MFA again before saving duplicate decisions.');
        $duplicates = collect($this->import['duplicates'] ?? [])->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();
        $decisions = array_intersect_key($this->duplicateResolutions, array_flip($duplicates));
        $resolutions = [];
        foreach ($duplicates as $id) {
            $decision = $decisions[$id] ?? null;
            if (! in_array($decision, ['create', 'update', 'skip'], true)) {
                throw ValidationException::withMessages(["duplicateResolutions.{$id}" => 'Choose create, update, or skip for this row.']);
            }
            $candidateId = $this->duplicateCandidates[$id] ?? null;
            $duplicate = collect($this->import['duplicates'] ?? [])->firstWhere('id', $id);
            $knownCandidate = collect($duplicate['candidates'] ?? [])->contains(
                fn (array $candidate): bool => (string) ($candidate['id'] ?? '') === $candidateId,
            );
            if ($decision === 'update' && ! $knownCandidate) {
                throw ValidationException::withMessages(["duplicateCandidates.{$id}" => 'Choose the person to update.']);
            }
            $householdCandidateId = $this->duplicateHouseholdCandidates[$id] ?? null;
            $householdCandidates = collect($duplicate['household_candidates'] ?? []);
            $knownHouseholdCandidate = $householdCandidates->contains(
                fn (array $candidate): bool => (string) ($candidate['id'] ?? '') === $householdCandidateId,
            );
            if ($decision !== 'skip' && $householdCandidates->isNotEmpty() && ! $knownHouseholdCandidate) {
                throw ValidationException::withMessages(["duplicateHouseholdCandidates.{$id}" => 'Choose the household to use.']);
            }
            $resolutions[$id] = array_filter([
                'decision' => $decision,
                'candidate_id' => $decision === 'update' ? $candidateId : null,
                'household_candidate_id' => $decision !== 'skip' ? $householdCandidateId : null,
            ]);
        }

        $this->syncImport($this->gateway()->resolveDuplicates(
            $this->studio(),
            $this->user(),
            $this->importId(),
            $this->importVersion(),
            $resolutions,
        ));
        Notification::make()->success()->title('Duplicate decisions saved')->send();
    }

    public function commitImport(): void
    {
        $this->authorizeWorkspace();
        $this->requireRecentConfirmation('Confirm your identity and MFA again before changing CRM records.');
        abort_unless((bool) ($this->import['canCommit'] ?? false), 409, 'The dry run is not ready to commit.');

        $this->syncImport($this->gateway()->commitImport(
            $this->studio(),
            $this->user(),
            $this->importId(),
            $this->importVersion(),
            (string) Str::uuid(),
        ));
        Notification::make()->success()->title('Import commit queued')->body('You can leave this page and safely resume if any rows need another attempt.')->send();
    }

    public function resumeImport(): void
    {
        $this->authorizeWorkspace();
        $this->requireRecentConfirmation('Confirm your identity and MFA again before retrying CRM updates.');
        abort_unless((bool) ($this->import['canResume'] ?? false), 409, 'This import has nothing to resume.');

        $this->syncImport($this->gateway()->resumeImport(
            $this->studio(),
            $this->user(),
            $this->importId(),
            $this->importVersion(),
            (string) Str::uuid(),
        ));
        Notification::make()->success()->title('Import resumed')->send();
    }

    public function refreshProgress(): void
    {
        $this->authorizeWorkspace();
        if ($this->import !== null) {
            $this->syncImport($this->gateway()->importWorkspace($this->studio(), $this->user(), $this->importId()));
        }
        if ($this->export !== null) {
            $this->syncExport($this->gateway()->exportWorkspace(
                $this->studio(),
                $this->user(),
                (string) $this->export['id'],
            ));
        }
    }

    public function resetImport(): void
    {
        $this->authorizeWorkspace();
        $this->reset(['import', 'mapping', 'duplicateResolutions', 'duplicateCandidates', 'duplicateHouseholdCandidates']);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'backendReady' => $this->backendReady(),
            'templateUrl' => $this->backendReady()
                ? $this->gateway()->templateDownloadUrl($this->studio(), $this->user())
                : null,
            'step' => $this->currentStep(),
        ];
    }

    private function authorizeWorkspace(): void
    {
        Gate::forUser($this->user())->authorize('create', [CrmImportBatch::class, $this->studio()]);
    }

    private function requireRecentConfirmation(string $message): void
    {
        try {
            app(CrmDataPortabilityStepUp::class)->assert($this->user(), app('session.store'));
        } catch (HttpException) {
            abort(423, $message);
        }
    }

    private function currentStep(): string
    {
        if ($this->import === null) {
            return 'prepare';
        }

        return match ((string) ($this->import['status'] ?? 'staged')) {
            'staged', 'mapping' => 'map',
            'previewing', 'previewed', 'needs_resolution' => 'review',
            'ready', 'queued', 'importing' => 'commit',
            'completed', 'completed_with_errors', 'failed' => 'outcome',
            default => 'map',
        };
    }

    private function backendReady(): bool
    {
        try {
            return app(CrmDataPortabilityGateway::class) instanceof CrmDataPortabilityGateway;
        } catch (\Throwable) {
            return false;
        }
    }

    private function gateway(): CrmDataPortabilityGateway
    {
        return app(CrmDataPortabilityGateway::class);
    }

    private function studio(): Studio
    {
        $studio = Filament::getTenant();
        abort_unless($studio instanceof Studio, 404);

        return $studio;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function importId(): string
    {
        $id = $this->import['id'] ?? null;
        abort_unless(is_string($id) && Str::isUlid($id), 404);

        return $id;
    }

    private function importVersion(): int
    {
        $version = $this->import['version'] ?? null;
        abort_unless(is_int($version) && $version > 0, 409, 'Refresh this import before continuing.');

        return $version;
    }

    private function syncImport(CrmImportWorkspace $workspace): void
    {
        $this->import = $workspace->toArray();
        $this->mapping = $workspace->mapping;
        $this->duplicateResolutions = collect($workspace->duplicates)
            ->filter(fn (array $row): bool => is_string($row['resolution'] ?? null))
            ->mapWithKeys(fn (array $row): array => [(string) $row['id'] => (string) $row['resolution']])
            ->all();
        $this->duplicateCandidates = collect($workspace->duplicates)
            ->filter(fn (array $row): bool => is_string($row['selected_candidate_id'] ?? null))
            ->mapWithKeys(fn (array $row): array => [(string) $row['id'] => (string) $row['selected_candidate_id']])
            ->all();
        $this->duplicateHouseholdCandidates = collect($workspace->duplicates)
            ->filter(fn (array $row): bool => is_string($row['selected_household_candidate_id'] ?? null))
            ->mapWithKeys(fn (array $row): array => [(string) $row['id'] => (string) $row['selected_household_candidate_id']])
            ->all();
        $this->dispatch('crm-portability-updated', step: $this->currentStep());
    }

    private function syncExport(CrmExportWorkspace $workspace): void
    {
        $this->export = $workspace->toArray();
        $this->dispatch('crm-portability-updated', step: 'export');
    }
}
