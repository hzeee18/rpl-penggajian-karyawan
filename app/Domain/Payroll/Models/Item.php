<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Company\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item komponen di versi master. type/is_taxable disalin dari katalog saat item dibuat.
 * calc_type contract = nilai kontrak (0 bila tidak ada); formula = hasil rumus.
 * item_role pph21 selalu dievaluasi paling akhir.
 */
final class Item extends Model
{
    use BelongsToCompany;

    protected $table = 'pay_items';

    protected $fillable = ['master_version_id', 'component_id', 'code', 'name', 'type', 'is_taxable', 'calc_type', 'formula', 'item_role', 'sort_order'];

    /**
     * Pilihan cara hitung "PPh21 TER otomatis" di form master (AUDIT.md §31): metode TER bulanan
     * PP 58/2023 (aturan NASIONAL, bukan aturan perusahaan). Angka tarif tetap dari tabel referensi
     * nasional `ter_bulanan` & `kategori_ptkp` (diisi Super Admin). Disimpan sebagai rumus biasa di versi
     * master, sehingga tercatat, bisa diaudit, dan versi lama tidak berubah bila pilihan ini kelak diubah.
     */
    public const CARA_PPH21_TER = 'pph21_ter';

    public const RUMUS_PPH21_TER = "ROUND(gross_taxable * LOOKUP('ter_bulanan', LOOKUP_TEXT('kategori_ptkp', ptkp_status), gross_taxable))";

    public function isPph21TerOtomatis(): bool
    {
        return $this->item_role === 'pph21' && $this->calc_type === 'formula' && $this->formula === self::RUMUS_PPH21_TER;
    }

    protected function casts(): array
    {
        return ['is_taxable' => 'boolean', 'sort_order' => 'integer'];
    }

    /** @return BelongsTo<Component, $this> */
    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class);
    }
}
