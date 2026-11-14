<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Import;

use App\Domain\Payroll\Models\RunEmployee;

/**
 * Data absensi/leave/lembur di periode payroll yang sudah final tidak boleh diubah.
 * "Final" = karyawan ada di payroll run yang sudah digenerate (is_blocking = 1: pending_approval / approved).
 * Run yang ditolak melepas kunci (is_blocking = 0).
 */
class KunciPeriode
{
    public function terkunci(int $employeeId, string $tanggal): bool
    {
        return RunEmployee::where('employee_id', $employeeId)
            ->where('is_blocking', true)
            ->where('period_start', '<=', $tanggal)
            ->where('period_end', '>=', $tanggal)
            ->exists();
    }
}
