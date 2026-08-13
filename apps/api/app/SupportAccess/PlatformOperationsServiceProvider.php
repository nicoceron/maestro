<?php

namespace App\SupportAccess;

use App\Outbox\Console\RelayOutboxCommand;
use App\SupportAccess\Console\ExpireSupportAccessCommand;
use Illuminate\Support\ServiceProvider;

final class PlatformOperationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Intentionally relies on Laravel's container autowiring for scoped services.
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([RelayOutboxCommand::class, ExpireSupportAccessCommand::class]);
        }
    }
}
