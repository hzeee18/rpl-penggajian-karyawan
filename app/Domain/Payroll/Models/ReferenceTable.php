<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Company\Tenancy\BelongsToCompanyOrNational;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Tabel referensi (TER, kategori PTKP, tarif lembur, ...). company_id NULL = nasional. */
final class ReferenceTable extends Model
{
    use BelongsToCompanyOrNational;

    protected $table = 'pay_reference_tables';

    protected $fillable = ['company_id', 'code', 'name', 'description', 'is_active'];

    protected $hidden = ['company_key'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<ReferenceTableVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ReferenceTableVersion::class)->orderByDesc('version_no');
    }

    /** Versi terbit terbaru yang berlaku pada tanggal (effective_from <= tanggal atau kosong). */
    public function versiBerlaku(string $tanggal): ?ReferenceTableVersion
    {
        return $this->versions()->where('status', 'published')
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $tanggal))
            ->orderByRaw('effective_from is null')->orderByDesc('effective_from')->orderByDesc('version_no')->first();
    }
}
