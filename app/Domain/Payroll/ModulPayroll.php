<?php

declare(strict_types=1);

namespace App\Domain\Payroll;


/**
 * Modul payroll (companies.payroll_baru menandai perusahaan yang hak menunya sudah dipasang, AUDIT.md §29, §68).
 * Bila aktif, fitur lama yang bergantung pada kontrak/payroll lama dialihkan ke modul baru.
 */
final class ModulPayroll
{
    /**
     * Menu lama yang digantikan modul baru => route tujuan pengalihan.
     * `pay-karyawan` ikut disembunyikan karena datanya digabung ke form Data Karyawan.
     */
    public const MENU_DIGANTIKAN = [
        'kontrak' => 'pay.kontrak.index',
        'payroll' => 'pay.run.index',
        'laporan' => 'pay.run.index',
        'slip' => 'pay.slip.index',
        'komponen' => 'pay.komponen.index',
        'pay-karyawan' => 'karyawan.index',
        'approval' => 'pay.approval.index',
        'import' => 'pay.import.index',
        'master-payroll' => 'pay.cutoff.index',
        'pajak' => 'pay.referensi.index',
    ];

    /** Modul payroll lama sudah dihapus (AUDIT.md §68): semua perusahaan memakai modul payroll ini. */
    public static function aktif(): bool
    {
        return true;
    }
}
