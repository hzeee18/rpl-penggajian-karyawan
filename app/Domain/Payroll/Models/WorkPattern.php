<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Company\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Pola hari kerja (AUDIT.md §55): hari ISO yang dihitung hari kerja, mis. Senin-Jumat (1-5) untuk kantor,
 * Senin-Minggu (1-7) untuk operasional. Dipakai variabel workdays, fungsi WORKDAYS, dan prorata hari kerja.
 */
final class WorkPattern extends Model
{
    use BelongsToCompany;

    public const NAMA_HARI = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

    /** Bila perusahaan belum punya pola sama sekali. */
    public const BAWAAN = [1, 2, 3, 4, 5];

    protected $table = 'pay_work_patterns';

    protected $fillable = ['code', 'name', 'work_days', 'is_default'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    /** @return list<int> */
    public function hari(): array
    {
        return array_values(array_map('intval', array_filter(explode(',', (string) $this->work_days))));
    }

    public function ringkas(): string
    {
        return implode(', ', array_map(fn ($h) => self::NAMA_HARI[$h], $this->hari()));
    }

    /** @return list<int> hari kerja karyawan: pola karyawan -> pola departemen -> bawaan perusahaan -> Senin-Jumat */
    public static function hariUntuk(?int $polaKaryawan, ?int $polaDepartemen): array
    {
        $id = $polaKaryawan ?? $polaDepartemen;
        $pola = $id !== null ? self::find($id) : null;
        $pola ??= self::where('is_default', true)->first();

        return $pola?->hari() ?: self::BAWAAN;
    }
}
