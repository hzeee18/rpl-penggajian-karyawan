<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Models\Karyawan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\ServiceProvider;

/** Modul payroll baru. Didaftarkan di bootstrap/providers.php. */
final class PayrollServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Modul lama menyimpan no_rekening sebagai teks biasa; payroll baru memakai salinan terenkripsi.
        Karyawan::saving(function (Karyawan $k): void {
            if ($k->isDirty('no_rekening')) {
                $no = (string) $k->getAttribute('no_rekening');
                $k->setAttribute('bank_account_no', $no === '' ? null : Crypt::encryptString($no));
            }
        });
    }
}
