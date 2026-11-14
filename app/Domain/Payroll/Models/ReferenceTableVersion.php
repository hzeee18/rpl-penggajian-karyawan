<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Casts\Tanggal;
use App\Domain\Company\Tenancy\BelongsToCompanyOrNational;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Versi isi tabel referensi. Versi terkunci (dipakai payroll final) tidak boleh diubah: buat versi baru. */
final class ReferenceTableVersion extends Model
{
    use BelongsToCompanyOrNational;

    protected $table = 'pay_reference_table_versions';

    protected $fillable = ['company_id', 'reference_table_id', 'version_no', 'status', 'effective_from', 'published_at', 'locked_at', 'created_by'];

    protected function casts(): array
    {
        return ['effective_from' => Tanggal::class, 'published_at' => 'datetime', 'locked_at' => 'datetime', 'version_no' => 'integer'];
    }

    /** @return BelongsTo<ReferenceTable, $this> */
    public function table(): BelongsTo
    {
        return $this->belongsTo(ReferenceTable::class, 'reference_table_id');
    }

    /** @return HasMany<ReferenceTableRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(ReferenceTableRow::class, 'version_id')->orderBy('sort_order')->orderBy('id');
    }

    public function bisaDiubah(): bool
    {
        return $this->status === 'draft' && $this->locked_at === null;
    }
}
