<?php

namespace App\DataPortability;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use LogicException;

final class DataPortabilityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->isProduction()) {
            $this->assertProductionConfiguration();
        }
        RateLimiter::for('crm-data-portability', function (Request $request): array {
            $routeStudio = $request->route('studio');
            $studio = (string) (is_object($routeStudio) ? $routeStudio->getKey() : ($routeStudio ?? 'none'));
            $actor = (string) ($request->user()?->getAuthIdentifier() ?? 'guest');
            $key = hash_hmac('sha256', $studio.'|'.$actor, (string) config('app.key'));

            return [Limit::perMinute(6)->by($key), Limit::perDay(30)->by($key)];
        });
    }

    private function assertProductionConfiguration(): void
    {
        $disk = (string) config('data-portability.disk');
        $definition = config("filesystems.disks.{$disk}");
        if (! is_array($definition) || ($definition['visibility'] ?? null) !== 'private' || ! in_array($definition['driver'] ?? null, ['local', 's3'], true)) {
            throw new LogicException('CRM data portability requires an explicitly private local or S3 disk.');
        }
        $bounds = ['max_upload_bytes' => [1_024, 10_485_760], 'max_rows' => [1, 25_000], 'artifact_ttl_hours' => [1, 168], 'resolution_ttl_minutes' => [1, 60], 'step_up_seconds' => [60, 600], 'row_lease_seconds' => [30, 600]];
        foreach ($bounds as $key => [$min,$max]) {
            $value = (int) config("data-portability.{$key}");
            if ($value < $min || $value > $max) {
                throw new LogicException("CRM data portability {$key} is outside its safe production bound.");
            }
        }
    }
}
