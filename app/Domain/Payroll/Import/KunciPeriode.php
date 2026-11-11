<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Import;

/**
 * Data absensi/leave/lembur di periode payroll yang sudah final tidak boleh diubah.
 * "Final" = karyawan sudah masuk payroll run berstatus pending_approval / approved (Fase 6).
 */
class KunciPeriode
{
    public function terkunci(int $employeeId, string $tanggal): bool
    {
        // Diisi di Fase 6 (tabel payroll run belum ada di fase ini).
        return false;
    }
}
