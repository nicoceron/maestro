<?php

namespace App\DataLifecycle\Filament\Pages;

use App\DataLifecycle\Actions\CancelTenantDeletion;
use App\DataLifecycle\Actions\RequestTenantDeletion;
use App\DataLifecycle\Actions\RequestTenantRestoreDrill;
use App\DataLifecycle\Actions\RestoreTenantDeletion;
use App\DataLifecycle\Actions\UpdateTenantRetentionPolicy;
use App\DataLifecycle\Models\TenantDeletionRequest;
use App\DataLifecycle\Models\TenantRestoreDrill;
use App\DataLifecycle\Models\TenantRetentionPolicy;
use App\Models\Studio;
use App\Models\User;
use App\TenantData\Actions\RequestTenantDataExport;
use App\TenantData\Models\TenantDataExport;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use UnitEnum;

final class TenantDataLifecycle extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Studio settings';

    protected static ?string $navigationLabel = 'Data & deletion';

    protected static ?string $title = 'Tenant data lifecycle';

    protected static ?string $slug = 'data-lifecycle';

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.pages.tenant-data-lifecycle';

    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Studio
            && auth()->user() instanceof User
            && Gate::allows('create', [TenantDataExport::class, $tenant]);
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('requestExport')
                ->label('Request export')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->requiresConfirmation()
                ->schema([
                    Checkbox::make('include_media_inventory')
                        ->label('Include safe media inventory')
                        ->helperText('Lists authorized clean attachments without exposing private storage paths.'),
                ])
                ->action(function (array $data): void {
                    $this->requireRecentConfirmation();
                    app(RequestTenantDataExport::class)->handle(
                        $this->studio(),
                        $this->user(),
                        (string) Str::uuid(),
                        (bool) ($data['include_media_inventory'] ?? false),
                    );
                    Notification::make()->success()->title('Export queued')->body('It will expire automatically after it is ready.')->send();
                }),
            Action::make('retention')
                ->label('Retention policy')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->fillForm(fn (): array => $this->retentionPolicy()->only([
                    'version',
                    'export_ttl_hours',
                    'deletion_cooling_off_days',
                    'deletion_quarantine_days',
                    'operational_retention_days',
                    'media_retention_days',
                    'audit_retention_days',
                ]))
                ->schema([
                    Hidden::make('version'),
                    TextInput::make('export_ttl_hours')->numeric()->minValue(1)->maxValue(168)->required(),
                    TextInput::make('deletion_cooling_off_days')->numeric()->minValue(1)->maxValue(3650)->required(),
                    TextInput::make('deletion_quarantine_days')->numeric()->minValue(1)->maxValue(3650)->required(),
                    TextInput::make('operational_retention_days')->numeric()->minValue(1)->maxValue(3650)->required(),
                    TextInput::make('media_retention_days')->numeric()->minValue(1)->maxValue(3650)->required(),
                    TextInput::make('audit_retention_days')->numeric()->minValue(1)->maxValue(3650)->required(),
                ])->action(function (array $data): void {
                    $this->requireRecentConfirmation();
                    app(UpdateTenantRetentionPolicy::class)->handle(
                        $this->studio(),
                        $this->user(),
                        collect($data)->except('version')->map(fn (mixed $value): int => (int) $value)->all(),
                        (int) $data['version'],
                    );
                    Notification::make()->success()->title('Retention policy updated')->send();
                }),
            Action::make('requestDeletion')
                ->label('Start deletion review')
                ->color('danger')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->modalHeading('Begin the reversible deletion lifecycle')
                ->modalDescription('This starts a cooling-off period and a verified export. It never immediately deletes studio data.')
                ->schema([
                    Textarea::make('reason')->required()->minLength(12)->maxLength(2000),
                    TextInput::make('confirmation_phrase')
                        ->label(fn (): string => 'Type DELETE '.$this->studio()->slug)
                        ->required(),
                ])->action(function (array $data): void {
                    $this->requireRecentConfirmation();
                    try {
                        app(RequestTenantDeletion::class)->handle(
                            $this->studio(),
                            $this->user(),
                            (string) $data['reason'],
                            (string) $data['confirmation_phrase'],
                            (string) Str::uuid(),
                        );
                    } catch (DomainException $exception) {
                        Notification::make()->danger()->title('Deletion review not started')->body($exception->getMessage())->send();

                        return;
                    }
                    Notification::make()->warning()->title('Cooling-off period started')->body('An independent studio administrator must approve after the cooling-off window.')->send();
                }),
        ];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'retentionPolicy' => $this->retentionPolicy(),
            'exports' => TenantDataExport::query()->where('studio_id', $this->studio()->getKey())->latest()->limit(10)->get(),
            'deletions' => TenantDeletionRequest::query()->where('studio_id', $this->studio()->getKey())->latest()->limit(10)->get(),
            'restoreDrills' => TenantRestoreDrill::query()->where('studio_id', $this->studio()->getKey())->latest()->limit(10)->get(),
        ];
    }

    public function cancelDeletion(string $id): void
    {
        $this->requireRecentConfirmation();
        $deletion = TenantDeletionRequest::query()->where('studio_id', $this->studio()->getKey())->whereKey($id)->firstOrFail();
        Gate::authorize('cancel', $deletion);
        app(CancelTenantDeletion::class)->handle($deletion, $this->user());
        Notification::make()->success()->title('Deletion request cancelled')->send();
    }

    public function restoreTenant(string $id): void
    {
        $this->requireRecentConfirmation();
        $deletion = TenantDeletionRequest::query()->where('studio_id', $this->studio()->getKey())->whereKey($id)->firstOrFail();
        Gate::authorize('restore', $deletion);
        app(RestoreTenantDeletion::class)->handle($deletion, $this->user());
        Notification::make()->success()->title('Studio access restored')->send();
    }

    public function runRestoreDrill(string $exportId): void
    {
        $this->requireRecentConfirmation();
        $export = TenantDataExport::query()->where('studio_id', $this->studio()->getKey())->whereKey($exportId)->firstOrFail();
        Gate::authorize('runRestoreDrill', $export);
        app(RequestTenantRestoreDrill::class)->handle($export, $this->user());
        Notification::make()->success()->title('Restore drill queued')->body('The drill validates checksums and invariants without writing tenant data.')->send();
    }

    private function retentionPolicy(): TenantRetentionPolicy
    {
        return TenantRetentionPolicy::query()->firstOrCreate(
            ['studio_id' => $this->studio()->getKey()],
            [
                'export_ttl_hours' => config('tenant-data.export_ttl_hours'),
                'deletion_cooling_off_days' => config('tenant-data.cooling_off_days'),
                'deletion_quarantine_days' => config('tenant-data.quarantine_days'),
                'operational_retention_days' => config('tenant-data.default_retention_days'),
                'media_retention_days' => config('tenant-data.default_retention_days'),
                'audit_retention_days' => config('tenant-data.default_retention_days'),
            ],
        );
    }

    private function requireRecentConfirmation(): void
    {
        $request = request();
        $passwordAt = (int) $request->session()->get('auth.password_confirmed_at', 0);
        $mfaAt = (int) $request->session()->get('auth.mfa_verified_at', 0);
        abort_unless(
            $passwordAt > 0 && now()->timestamp - $passwordAt <= 300
                && $mfaAt > 0 && now()->timestamp - $mfaAt <= 300
                && $this->user()->hasEnabledTwoFactorAuthentication(),
            423,
            'Confirm your identity and MFA again before changing tenant data lifecycle settings.',
        );
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
}
