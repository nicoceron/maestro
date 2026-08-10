<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\EnsureTrustedRequestOrigin;
use App\Http\Middleware\ResolveStudioTenant;
use App\Http\Middleware\SetDatabaseUserContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->trustHosts(
            at: fn (): array => config('security.trusted_hosts'),
            subdomains: false,
        );
        $middleware->append([
            EnsureTrustedRequestOrigin::class,
            AddSecurityHeaders::class,
        ]);

        $middleware->alias([
            'database.user-context' => SetDatabaseUserContext::class,
            'studio.member' => ResolveStudioTenant::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
