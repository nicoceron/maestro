<?php

use App\DataPortability\DataPortabilityServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\FortifyServiceProvider;
use App\SupportAccess\Filament\SupportPanelProvider;
use App\SupportAccess\PlatformOperationsServiceProvider;
use App\TenantData\TenantDataLifecycleServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    AdminPanelProvider::class,
    PlatformOperationsServiceProvider::class,
    SupportPanelProvider::class,
    TenantDataLifecycleServiceProvider::class,
    DataPortabilityServiceProvider::class,
];
