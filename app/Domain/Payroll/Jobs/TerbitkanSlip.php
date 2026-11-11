<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Jobs;

use App\Domain\Company\Tenancy\CurrentCompany;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Payroll\Slip\BuatPdfSlip;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Terbitkan slip (baris + PDF berpassword) untuk semua karyawan run yang disetujui. Konteks perusahaan eksplisit. */
final class TerbitkanSlip implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 0;

    public function __construct(public readonly int $runId, public readonly int $companyId) {}

    public function handle(CurrentCompany $konteks, BuatPdfSlip $pdf): void
    {
        $konteks->runAs($this->companyId, function () use ($pdf) {
            $run = PayrollRun::findOrFail($this->runId);
            if ($run->status !== PayrollRun::APPROVED) {
                return;
            }
            foreach ($run->employees()->get(['id', 'employee_id']) as $re) {
                $slip = Payslip::firstOrCreate(['run_employee_id' => $re->id], ['employee_id' => $re->employee_id]);
                if ($slip->file_path === null) {
                    $pdf->handle($slip);
                }
            }
        });
    }
}
