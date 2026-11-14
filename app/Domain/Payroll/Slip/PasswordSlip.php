<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Slip;

use App\Domain\Payroll\Models\RunEmployee;

/**
 * Password PDF slip (keputusan AUDIT.md §9 butir 6, menggantikan PAYROLL_SPEC.md):
 * 2 digit TERAKHIR nomor karyawan + 4 digit terakhir nomor rekening.
 * Hanya angka yang dihitung (mis. K0001 -> "01"). Rekening didekripsi hanya saat membuat PDF.
 */
final class PasswordSlip
{
    /** @return string|null null bila data tidak cukup (slip tidak diterbitkan tanpa password) */
    public static function untuk(RunEmployee $re): ?string
    {
        return self::dari((string) $re->employee_no, $re->bank_account_no);
    }

    public static function dari(string $nomorKaryawan, ?string $rekening): ?string
    {
        $karyawan = preg_replace('/\D/', '', $nomorKaryawan) ?? '';
        $rek = preg_replace('/\D/', '', (string) $rekening) ?? '';
        if (strlen($karyawan) < 2 || strlen($rek) < 4) {
            return null;
        }

        return substr($karyawan, -2).substr($rek, -4);
    }
}
