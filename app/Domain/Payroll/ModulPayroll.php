<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Domain\Company\Models\Company;
use App\Domain\Company\Tenancy\CurrentCompany;

/**
 * Saklar "Payroll Baru aktif" per perusahaan (companies.payroll_baru, AUDIT.md §29).
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
    ];

    /** Apakah perusahaan dalam konteks saat ini memakai modul payroll baru. */
    public static function aktif(): bool
    {
        $id = app(CurrentCompany::class)->id();

        return $id !== null && (bool) Company::whereKey($id)->value('payroll_baru');
    }
}
