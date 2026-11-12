<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // multi-tenant: paling depan agar route model binding juga dibatasi perusahaan
        $middleware->web(prepend: [\App\Domain\Company\Tenancy\TenantRequestContext::class]);
        $middleware->alias([
            'akses' => \App\Http\Middleware\CekAkses::class,
            'aktif' => \App\Http\Middleware\AkunAktif::class,
            'super-admin' => \App\Domain\Company\Tenancy\HanyaSuperAdmin::class,
            'modul-lama' => \App\Http\Middleware\AlihkanModulLama::class,
        ]);
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/dashboard');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
