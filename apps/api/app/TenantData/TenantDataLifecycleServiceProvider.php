<?php

namespace App\TenantData;

use App\DataLifecycle\Console\AdvanceTenantDeletionLifecycleCommand;
use App\DataLifecycle\Filament\Pages\TenantDataLifecycle;
use App\TenantData\Console\ExpireTenantDataExportsCommand;
use App\TenantData\Listeners\RecordCurrentSessionMfaVerification;
use Filament\Panel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;

final class TenantDataLifecycleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/tenant-data.php', 'tenant-data');

        if (config('filesystems.disks.tenant_exports') === null) {
            config([
                'filesystems.disks.tenant_exports' => [
                    'driver' => 'local',
                    'root' => storage_path('app/private/tenant-exports'),
                    'visibility' => 'private',
                    'throw' => true,
                    'report' => true,
                ],
            ]);
        }

        Panel::configureUsing(
            fn (Panel $panel): Panel => $panel->getId() === 'admin'
                ? $panel->pages([TenantDataLifecycle::class])
                : $panel,
            isImportant: true,
        );
    }

    public function boot(): void
    {
        Route::middleware('api')->prefix('api')->group(base_path('routes/tenant-data.php'));
        Event::listen(
            ValidTwoFactorAuthenticationCodeProvided::class,
            RecordCurrentSessionMfaVerification::class,
        );

        if ($this->app->runningInConsole()) {
            $this->commands([
                ExpireTenantDataExportsCommand::class,
                AdvanceTenantDeletionLifecycleCommand::class,
            ]);
            require base_path('routes/tenant-data-console.php');
        }
    }
}
