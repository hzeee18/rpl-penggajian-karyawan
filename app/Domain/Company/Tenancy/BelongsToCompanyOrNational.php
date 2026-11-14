<?php

declare(strict_types=1);

namespace App\Domain\Company\Tenancy;

use App\Domain\Company\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Untuk data yang bisa milik perusahaan ATAU nasional (company_id NULL, mis. tabel TER).
 * - Baca: data perusahaan aktif + data nasional. Tanpa konteks: hanya data nasional.
 * - Buat: company_id dari konteks; data nasional dibuat dengan menyetel company_id = null secara eksplisit
 *   (hanya Super Admin, diperiksa di action/controller).
 * - company_id tidak boleh dipindah.
 */
trait BelongsToCompanyOrNational
{
    public static function bootBelongsToCompanyOrNational(): void
    {
        static::addGlobalScope('company_or_national', function (Builder $builder): void {
            $konteks = app(CurrentCompany::class);
            if ($konteks->isLintasPerusahaan()) {
                return;
            }
            $kolom = $builder->getModel()->qualifyColumn('company_id');
            $companyId = $konteks->id();
            $builder->where(fn ($q) => $companyId === null ? $q->whereNull($kolom) : $q->whereNull($kolom)->orWhere($kolom, $companyId));
        });

        static::creating(function (Model $model): void {
            if (array_key_exists('company_id', $model->getAttributes())) {
                return; // diisi eksplisit (termasuk null = nasional)
            }
            $companyId = app(CurrentCompany::class)->id();
            if ($companyId === null) {
                throw new RuntimeException('Konteks perusahaan belum ditentukan untuk '.$model::class.'.');
            }
            $model->setAttribute('company_id', $companyId);
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('company_id')) {
                throw new RuntimeException('company_id tidak boleh diubah.');
            }
        });
    }

    public function isNasional(): bool
    {
        return $this->getAttribute('company_id') === null;
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
