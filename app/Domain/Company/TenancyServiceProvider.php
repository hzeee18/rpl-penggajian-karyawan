<?php

declare(strict_types=1);

namespace App\Domain\Company;

use App\Domain\Company\Tenancy\CurrentCompany;
use App\Domain\Company\Tenancy\TenantAwareUserProvider;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Infrastruktur multi-tenant. Didaftarkan di bootstrap/providers.php. */
final class TenancyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // dipakai config/auth.php: providers.users.driver = 'tenant-eloquent'
        Auth::provider('tenant-eloquent', fn ($app, array $config) => new TenantAwareUserProvider($app['hash'], $config['model']));

        // Lintas perusahaan: migration boleh membaca semua perusahaan (keputusan pemilik, AUDIT.md §14),
        // mis. migration lama yang merapikan nomor kontrak seluruh data.
        Event::listen(MigrationsStarted::class, fn () => app(CurrentCompany::class)->masukLintas('migration'));
        Event::listen(MigrationsEnded::class, fn () => app(CurrentCompany::class)->keluarLintas());
    }
}
