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

    protected $fillable = ['master_version_id', 'component_id', 'code', 'name', 'type', 'is_taxable', 'is_tax_deductible', 'calc_type', 'formula', 'item_role', 'sort_order'];

    /**
     * Pilihan cara hitung "PPh21 TER otomatis" di form master (AUDIT.md §31): metode TER bulanan
     * PP 58/2023 (aturan NASIONAL, bukan aturan perusahaan). Angka tarif tetap dari tabel referensi
     * nasional `ter_bulanan` & `kategori_ptkp` (diisi Super Admin). Disimpan sebagai rumus biasa di versi
     * master, sehingga tercatat, bisa diaudit, dan versi lama tidak berubah bila pilihan ini kelak diubah.
     */
    public const CARA_PPH21_TER = 'pph21_ter';

    public const RUMUS_PPH21_TER = "ROUND(gross_taxable * LOOKUP('ter_bulanan', LOOKUP_TEXT('kategori_ptkp', ptkp_status), gross_taxable))";

    /**
     * Pilihan "PPh21 Desember / resign otomatis": PPh21 Pasal 17 atas penghasilan setahun dikurangi PPh21 yang sudah
     * dipotong Januari s/d bulan sebelumnya (PP 58/2023, PMK 168/2023). Hasil negatif = lebih potong (tetap dicatat).
     * Angka dari tabel referensi NASIONAL (diisi Super Admin, lihat Pph21TahunanSeeder):
     * - `ptkp_setahun`  : kunci status PTKP -> PTKP setahun.
     * - `biaya_jabatan` : kunci `persen` dan `maks_bulanan`.
     * - `pasal17`       : rentang PKP, kunci `batas` (batas bawah lapisan), `tarif`, `dasar` (pajak kumulatif s/d batas bawah).
     * Riwayat bulan sebelumnya dari variabel bawaan ytd_* (pay_tax_monthly_histories, payroll yang sudah disetujui).
     */
    public const CARA_PPH21_DESEMBER = 'pph21_desember';

    private const BRUTO_SETAHUN = '(ytd_gross_taxable + gross_taxable)';

    private const PKP_SETAHUN = 'FLOOR(MAX(0, '.self::BRUTO_SETAHUN
        ." - MIN(".self::BRUTO_SETAHUN." * LOOKUP('biaya_jabatan', 'persen', 0), LOOKUP('biaya_jabatan', 'maks_bulanan', 0) * tax_months)"
        ." - (ytd_tax_deduction + tax_deduction) - LOOKUP('ptkp_setahun', ptkp_status, 0)) / 1000) * 1000";

    public const RUMUS_PPH21_DESEMBER = "ROUND(LOOKUP('pasal17', 'dasar', ".self::PKP_SETAHUN.')'
        .' + ('.self::PKP_SETAHUN." - LOOKUP('pasal17', 'batas', ".self::PKP_SETAHUN."))"
        ." * LOOKUP('pasal17', 'tarif', ".self::PKP_SETAHUN.')) - ytd_pph21';

    public function isPph21TerOtomatis(): bool
    {
        return $this->caraOtomatis() === self::CARA_PPH21_TER;
    }

    /** Pilihan cara hitung siap pakai yang dipakai item ini (null = rumus/kontrak biasa). */
    public function caraOtomatis(): ?string
    {
        if ($this->item_role !== 'pph21' || $this->calc_type !== 'formula') {
            return null;
        }

        return match ($this->formula) {
            self::RUMUS_PPH21_TER => self::CARA_PPH21_TER,
            self::RUMUS_PPH21_DESEMBER => self::CARA_PPH21_DESEMBER,
            default => null,
        };
    }

    protected function casts(): array
    {
        return ['is_taxable' => 'boolean', 'is_tax_deductible' => 'boolean', 'sort_order' => 'integer'];
    }

    /** @return BelongsTo<Component, $this> */
    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class);
    }
}
