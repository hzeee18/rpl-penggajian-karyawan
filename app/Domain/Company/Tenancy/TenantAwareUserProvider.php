<?php

declare(strict_types=1);

namespace App\Domain\Company\Tenancy;

use Illuminate\Auth\EloquentUserProvider;

/**
 * Provider auth yang mencari user TANPA CompanyScope.
 * Diperlukan karena perusahaan aktif ditentukan dari user itu sendiri: saat sesi dimuat
 * belum ada konteks, sehingga query ber-scope akan selalu kosong (user ter-logout).
 * username tetap unik global, jadi pencarian lintas perusahaan di sini aman.
 */
final class TenantAwareUserProvider extends EloquentUserProvider
{
    protected function newModelQuery($model = null)
    {
        return parent::newModelQuery($model)->withoutGlobalScope(CompanyScope::class);
    }
}
