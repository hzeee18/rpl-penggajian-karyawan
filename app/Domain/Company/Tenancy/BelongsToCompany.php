<?php

declare(strict_types=1);

namespace App\Domain\Company\Tenancy;

use App\Domain\Company\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Dipasang di SEMUA model bertenant (lama dan baru), lihat AGENTS.md "Aturan multi-tenant".
 * - Query otomatis dibatasi ke perusahaan aktif (CompanyScope, aturan konteks di CurrentCompany).
 * - company_id terisi otomatis saat create; create tanpa konteks perusahaan ditolak.
 * - company_id tidak boleh dipindah ke perusahaan lain setelah dibuat.
 *
 * Global scope TIDAK berlaku untuk DB::table(), query mentah, dan join manual (diaudit di fase 1c).
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('company_id') !== null) {
                return;
            }

            $companyId = app(CurrentCompany::class)->id();
            if ($companyId === null) {
                throw new RuntimeException('Konteks perusahaan belum ditentukan untuk '.$model::class.'.');
            }

            $model->setAttribute('company_id', $companyId);
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('company_id') && $model->getOriginal('company_id') !== null) {
                throw new RuntimeException('company_id tidak boleh diubah.');
            }
        });
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
