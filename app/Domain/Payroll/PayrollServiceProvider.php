<?php

declare(strict_types=1);

namespace App\Domain\Payroll;

use App\Domain\Payroll\Events\PayrollDisetujui;
use App\Domain\Payroll\Jobs\TerbitkanSlip;
use App\Models\Karyawan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Modul payroll baru. Didaftarkan di bootstrap/providers.php. */
final class PayrollServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Fase 7: setelah Manager menyetujui, slip + PDF diterbitkan di background (konteks perusahaan dibawa event)
        Event::listen(
            PayrollDisetujui::class,
            fn (PayrollDisetujui $e) => TerbitkanSlip::dispatch($e->runId, $e->companyId),
        );

        // Modul lama menyimpan no_rekening sebagai teks biasa; payroll baru memakai salinan terenkripsi.
        Karyawan::saving(function (Karyawan $k): void {
            if ($k->isDirty('no_rekening')) {
                $no = (string) $k->getAttribute('no_rekening');
                $k->setAttribute('bank_account_no', $no === '' ? null : Crypt::encryptString($no));
            }
        });
    }
}
