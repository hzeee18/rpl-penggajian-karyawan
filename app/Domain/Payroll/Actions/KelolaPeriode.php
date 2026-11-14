<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Payroll\Models\PayPeriod;
use App\Domain\Payroll\Models\PeriodConfig;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Periode payroll (AUDIT.md §53). Periode bulan baru dibuat dari aturan Cut Off; bila periode bulan sebelumnya
 * sudah ada, tanggal mulainya = selesai periode itu + 1 (menyambung tanpa celah / tumpang tindih).
 */
final class KelolaPeriode
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Periode bulan $bulan (Y-m): yang sudah ada, atau dibuat dari Cut Off. */
    public function untukBulan(string $bulan): PayPeriod
    {
        return DB::transaction(function () use ($bulan) {
            $ada = PayPeriod::where('period_month', $bulan)->lockForUpdate()->first();
            if ($ada !== null) {
                return $ada;
            }
            [$mulai, $selesai] = $this->rentangBaru($bulan);

            $periode = PayPeriod::create(['period_month' => $bulan, 'start_date' => $mulai->toDateString(), 'end_date' => $selesai->toDateString(), 'created_by' => Auth::id()]);
            $this->audit->log('created', $periode, new: $periode->getAttributes());

            return $periode;
        });
    }

    /**
     * Rentang periode bulan baru: dari aturan Cut Off, lalu disambungkan dengan periode bulan sebelum/sesudahnya.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function rentangBaru(string $bulan): array
    {
        $config = PeriodConfig::first()
            ?? throw ValidationException::withMessages(['periode' => 'Atur cut off payroll dulu di menu Cut Off.']);
        try {
            [$mulai, $selesai] = $config->rentang($bulan);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['periode' => $e->getMessage()]);
        }
        $sebelum = PayPeriod::where('period_month', CarbonImmutable::createFromFormat('!Y-m', $bulan)->subMonthNoOverflow()->format('Y-m'))->first();
        if ($sebelum !== null) {
            $mulai = CarbonImmutable::parse($sebelum->end_date)->addDay();
        }
        $sesudah = PayPeriod::where('period_month', CarbonImmutable::createFromFormat('!Y-m', $bulan)->addMonthNoOverflow()->format('Y-m'))->first();
        if ($sesudah !== null) {
            $selesai = CarbonImmutable::parse($sesudah->start_date)->subDay();
        }
        if ($selesai->lt($mulai)) {
            throw ValidationException::withMessages(['periode' => 'Periode '.nama_periode($bulan).' tidak punya hari karena periode sebelum dan sesudahnya sudah menutup rentang ini.']);
        }

        return [$mulai, $selesai];
    }

    /** Bulan periode berikutnya: sesudah periode terakhir, atau bulan ini bila belum ada periode. */
    public function bulanBerikutnya(): string
    {
        $terakhir = PayPeriod::orderByDesc('period_month')->value('period_month');

        return $terakhir === null ? now('Asia/Jakarta')->format('Y-m') : CarbonImmutable::createFromFormat('!Y-m', $terakhir)->addMonthNoOverflow()->format('Y-m');
    }

    /** Buat periode bulan sesudah periode terakhir (atau bulan ini bila belum ada). */
    public function buatBerikutnya(): PayPeriod
    {
        return $this->untukBulan($this->bulanBerikutnya());
    }

    /** Ubah tanggal selesai; periode bulan berikutnya (bila ada) ikut bergeser mulainya. */
    public function ubahSelesai(PayPeriod $periode, string $selesai): void
    {
        DB::transaction(function () use ($periode, $selesai) {
            $periode = PayPeriod::lockForUpdate()->findOrFail($periode->id);
            $baru = CarbonImmutable::parse($selesai);
            if ($periode->status() !== 'terbuka') {
                throw ValidationException::withMessages(['end_date' => 'Periode ini sudah dipakai payroll, jadi tanggalnya tidak bisa diubah. Kalau payroll-nya masih draft, hapus dulu draft tersebut.']);
            }
            if ($baru->lt(CarbonImmutable::parse($periode->start_date))) {
                throw ValidationException::withMessages(['end_date' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.']);
            }
            $berikut = PayPeriod::where('period_month', CarbonImmutable::createFromFormat('!Y-m', $periode->period_month)->addMonthNoOverflow()->format('Y-m'))->lockForUpdate()->first();
            if ($berikut !== null) {
                if ($berikut->status() !== 'terbuka') {
                    throw ValidationException::withMessages(['end_date' => 'Periode '.nama_periode($berikut->period_month).' sudah dipakai payroll, jadi tanggal selesai periode ini tidak bisa digeser.']);
                }
                if ($baru->addDay()->gt(CarbonImmutable::parse($berikut->end_date))) {
                    throw ValidationException::withMessages(['end_date' => 'Tanggal selesai tidak boleh melewati akhir periode '.nama_periode($berikut->period_month).' ('.tgl($berikut->end_date).').']);
                }
            }

            $lama = $periode->end_date->toDateString();
            $periode->forceFill(['end_date' => $baru->toDateString()])->save();
            $this->audit->log('updated', $periode, old: ['end_date' => $lama], new: ['end_date' => $baru->toDateString()]);
            if ($berikut !== null) {
                $mulaiLama = $berikut->start_date->toDateString();
                $berikut->forceFill(['start_date' => $baru->addDay()->toDateString()])->save();
                $this->audit->log('updated', $berikut, old: ['start_date' => $mulaiLama], new: ['start_date' => $berikut->start_date->toDateString()]);
            }
        });
    }

    /**
     * Hapus periode yang belum dipakai payroll (AUDIT.md §56). Hanya periode paling akhir, supaya
     * periode lain tetap menyambung tanpa celah.
     */
    public function hapus(PayPeriod $periode): void
    {
        DB::transaction(function () use ($periode) {
            $periode = PayPeriod::lockForUpdate()->findOrFail($periode->id);
            if ($periode->status() !== 'terbuka') {
                throw ValidationException::withMessages(['periode' => 'Periode '.nama_periode($periode->period_month).' sudah dipakai payroll, jadi tidak bisa dihapus. Kalau payroll-nya masih draft, hapus dulu draft tersebut.']);
            }
            if (PayPeriod::where('period_month', '>', $periode->period_month)->exists()) {
                throw ValidationException::withMessages(['periode' => 'Hanya periode terakhir yang bisa dihapus, supaya tanggal periode tetap menyambung.']);
            }
            $this->audit->log('deleted', $periode, old: $periode->getAttributes());
            $periode->delete();
        });
    }
}
