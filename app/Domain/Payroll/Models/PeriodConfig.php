<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Company\Tenancy\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/** Konfigurasi cut off: satu per perusahaan. */
final class PeriodConfig extends Model
{
    use BelongsToCompany;

    protected $table = 'pay_period_configs';

    protected $fillable = ['start_day', 'end_day', 'start_in_previous_month'];

    protected function casts(): array
    {
        return ['start_day' => 'integer', 'end_day' => 'integer', 'start_in_previous_month' => 'boolean'];
    }

    /**
     * Rentang tanggal periode bulan $periode (Y-m). Contoh lintas bulan 26/25 + start_in_previous_month:
     * 2026-11 => 2026-10-26 s/d 2026-11-25. Hari di luar panjang bulan dipotong ke akhir bulan.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function rentang(string $periode): array
    {
        $bulan = CarbonImmutable::createFromFormat('!Y-m', $periode, 'Asia/Jakarta');
        $bulanMulai = $this->start_in_previous_month ? $bulan->subMonthNoOverflow() : $bulan;
        $mulai = $bulanMulai->day(min($this->start_day, $bulanMulai->daysInMonth));
        $selesai = $bulan->day(min($this->end_day, $bulan->daysInMonth));

        if ($selesai->lt($mulai)) {
            throw new \InvalidArgumentException('Konfigurasi cut off menghasilkan tanggal selesai sebelum tanggal mulai.');
        }

        return [$mulai, $selesai];
    }
}
