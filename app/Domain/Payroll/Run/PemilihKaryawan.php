<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Run;

use App\Domain\Payroll\Models\Contract;
use App\Domain\Payroll\Models\Employee;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\RunEmployee;
use Illuminate\Support\Collection;

/**
 * Karyawan yang ikut run: hanya karyawan aktif dengan kontrak aktif (is_active = 1) dalam lingkup
 * (semua / departemen / perorangan). Karyawan yang sudah punya payroll aktif (is_blocking) di periode
 * yang beririsan di-skip dan dicatat alasannya.
 */
final class PemilihKaryawan
{
    /** @return array{ikut: Collection<int, array{employee: Employee, contract: Contract}>, skip: list<array{employee_id: int, nik: string, nama: string, alasan: string}>} */
    public function pilih(PayrollRun $run): array
    {
        $pilihan = array_map('intval', $run->employee_selection ?? []);
        $karyawan = Employee::with(['departemen', 'position', 'pajak'])
            ->when($run->employee_scope === 'department', fn ($q) => $q->whereIn('departemen_id', $pilihan))
            ->when($run->employee_scope === 'individual', fn ($q) => $q->whereIn('id', $pilihan))
            ->orderBy('nik')->get();

        $kontrak = Contract::active()->whereIn('employee_id', $karyawan->pluck('id'))->get()->keyBy('employee_id');
        $sudahAda = RunEmployee::where('is_blocking', true)->where('run_id', '!=', $run->id)
            ->whereIn('employee_id', $karyawan->pluck('id'))
            ->where('period_start', '<=', $run->period_end->toDateString())->where('period_end', '>=', $run->period_start->toDateString())
            ->pluck('employee_id')->flip();

        $ikut = collect();
        $skip = [];
        foreach ($karyawan as $e) {
            $alasan = match (true) {
                ! $e->is_active => 'karyawan nonaktif',
                ! $kontrak->has($e->id) => 'tidak punya kontrak aktif',
                $sudahAda->has($e->id) => 'sudah punya payroll aktif di periode ini',
                default => null,
            };
            if ($alasan === null) {
                $ikut->push(['employee' => $e, 'contract' => $kontrak[$e->id]]);
            } elseif ($run->employee_scope === 'individual' || $alasan !== 'karyawan nonaktif') {
                $skip[] = ['employee_id' => (int) $e->id, 'nik' => (string) $e->nik, 'nama' => (string) $e->nama, 'alasan' => $alasan];
            }
        }

        return ['ikut' => $ikut, 'skip' => $skip];
    }

    /** Kontrak aktif yang tanggal akhirnya sudah lewat sebelum akhir periode (peringatan sebelum preview). */
    public function kontrakKedaluwarsa(PayrollRun $run): Collection
    {
        return Contract::active()->with('employee')->whereNotNull('end_date')
            ->where('end_date', '<', $run->period_end->toDateString())->orderBy('end_date')->get();
    }
}
