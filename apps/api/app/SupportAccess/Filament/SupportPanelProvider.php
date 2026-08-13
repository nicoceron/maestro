<?php

namespace App\SupportAccess\Filament;

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\EnforceBrowserSessionLifetime;
use App\Http\Middleware\SetDatabaseUserContext;
use App\SupportAccess\Http\Middleware\AuthenticateSupportOperator;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

final class SupportPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('support')->path('support')->brandName('Maestro Support')
            ->colors(['primary' => Color::Amber])
            ->strictAuthorization()
            ->discoverResources(in: app_path('SupportAccess/Filament/Resources'), for: 'App\\SupportAccess\\Filament\\Resources')
            ->pages([Dashboard::class])
            ->middleware([
                EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class,
                SetDatabaseUserContext::class, EnforceBrowserSessionLifetime::class,
                AuthenticateSession::class, ShareErrorsFromSession::class,
                PreventRequestForgery::class, SubstituteBindings::class,
                DisableBladeIconComponents::class, DispatchServingFilamentEvent::class,
                AddSecurityHeaders::class,
            ])->authMiddleware([AuthenticateSupportOperator::class]);
    }
}
