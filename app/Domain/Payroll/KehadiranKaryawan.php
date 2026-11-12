<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Domain\Payroll\Models\Attendance;
use App\Domain\Payroll\Models\Leave;
use App\Domain\Payroll\Models\Overtime;
use App\Domain\Payroll\Models\PeriodConfig;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Kehadiran SATU karyawan dari tabel modul payroll baru (pay_attendances, pay_leaves, pay_overtimes),
 * untuk tampilan karyawan (dashboard & riwayat). Hanya menampilkan data apa adanya: tidak ada aturan
 * perusahaan (alpha, potongan, uang makan) di sini, itu dihitung master payroll saat payroll.
 */
final class KehadiranKaryawan
{
    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} rentang periode menurut cut off perusahaan */
    public static function rentang(string $periode): array
    {
        $bulan = CarbonImmutable::createFromFormat('!Y-m', $periode);

        return PeriodConfig::first()?->rentang($periode) ?? [$bulan->startOfMonth(), $bulan->endOfMonth()];
    }

    /**
     * @return array{rekap: array{hadir: int, terlambat: int, leave: int, leave_per_tipe: array<string, int>, jam_lembur: string}, baris: Collection<int, object>}
     */
    public static function untuk(int $employeeId, CarbonImmutable $awal, CarbonImmutable $akhir): array
    {
        $r = [$awal->toDateString(), $akhir->toDateString()];
        $hadir = Attendance::where('employee_id', $employeeId)->whereBetween('attendance_date', $r)->get()
            ->keyBy(fn ($a) => $a->attendance_date->toDateString());
        $leave = Leave::with('leaveType')->where('employee_id', $employeeId)->whereBetween('leave_date', $r)->get()
            ->groupBy(fn ($l) => $l->leave_date->toDateString());
        $lembur = Overtime::where('employee_id', $employeeId)->whereBetween('overtime_date', $r)->get()
            ->groupBy(fn ($o) => $o->overtime_date->toDateString());

        $tanggal = $hadir->keys()->merge($leave->keys())->merge($lembur->keys())->unique()->sortDesc()->values();
        $baris = $tanggal->map(function (string $t) use ($hadir, $leave, $lembur) {
            $a = $hadir[$t] ?? null;
            $l = ($leave[$t] ?? collect())->first();
            $o = $lembur[$t] ?? collect();
            $jamLembur = $o->reduce(fn ($n, $x) => bcadd($n, (string) $x->duration_hours, 2), '0');
            $telat = $a !== null && bccomp((string) $a->late_hours, '0', 2) > 0;

            return (object) [
                'tanggal' => CarbonImmutable::parse($t),
                'status' => $l ? 'leave' : ($a ? ($telat ? 'terlambat' : 'hadir') : null),
                'label' => $l ? ($l->leaveType?->name ?? 'Leave') : ($a ? ($telat ? 'Terlambat' : 'Hadir') : 'Lembur'),
                'keterangan' => $l?->note ?? ($a ? self::jam($a->check_in).' – '.self::jam($a->check_out).($telat ? ' · terlambat '.self::angka((string) $a->late_hours).' jam' : '') : null) ?? $o->first()?->note,
                'lembur' => $jamLembur,
            ];
        });

        $perTipe = $leave->flatten()->countBy(fn ($l) => $l->leaveType?->name ?? 'Leave')->sortKeys()->all();

        return [
            'rekap' => [
                'hadir' => $hadir->count(),
                'terlambat' => $hadir->filter(fn ($a) => bccomp((string) $a->late_hours, '0', 2) > 0)->count(),
                'leave' => array_sum($perTipe),
                'leave_per_tipe' => $perTipe,
                'jam_lembur' => $lembur->flatten()->reduce(fn ($n, $x) => bcadd($n, (string) $x->duration_hours, 2), '0'),
            ],
            'baris' => $baris,
        ];
    }

    /** "1.50" -> "1,5"; "2.00" -> "2" */
    public static function angka(string $n): string
    {
        $s = str_contains($n, '.') ? rtrim(rtrim($n, '0'), '.') : $n;

        return str_replace('.', ',', $s === '' ? '0' : $s);
    }

    private static function jam(?string $jam): string
    {
        return $jam ? substr($jam, 0, 5) : '-';
    }
}
