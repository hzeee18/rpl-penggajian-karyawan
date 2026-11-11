<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Jobs;

use App\Domain\Company\Tenancy\CurrentCompany;
use App\Domain\Payroll\Engine\RumusException;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Run\HitungRunKaryawan;
use App\Domain\Payroll\Run\PemilihKaryawan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Hitung preview payroll di background, per potongan karyawan. Job membawa konteks perusahaan
 * secara eksplisit (runAs), tidak bergantung pada user login.
 */
final class ProsesPratinjau implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 0;

    public const POTONGAN = 200;

    public function __construct(public readonly int $runId, public readonly int $companyId) {}

    public function handle(CurrentCompany $konteks, PemilihKaryawan $pemilih, HitungRunKaryawan $hitung): void
    {
        $konteks->runAs($this->companyId, function () use ($pemilih, $hitung) {
            $run = PayrollRun::with('masterVersion')->findOrFail($this->runId);
            if (! $run->isDraft()) {
                return;
            }
            $run->forceFill(['job_status' => 'running', 'job_started_at' => now(), 'job_error' => null])->save();

            try {
                ['ikut' => $ikut, 'skip' => $skip] = $pemilih->pilih($run);
                $run->employees()->whereNotIn('employee_id', $ikut->pluck('employee.id'))->delete();

                $versiReferensi = [];
                foreach ($ikut->chunk(self::POTONGAN) as $potongan) {
                    foreach ($potongan as ['employee' => $e, 'contract' => $c]) {
                        try {
                            $versiReferensi = $hitung->handle($run, $e, $c) + $versiReferensi;
                        } catch (RumusException $ex) {
                            $run->employees()->where('employee_id', $e->id)->delete();
                            $skip[] = ['employee_id' => (int) $e->id, 'nik' => (string) $e->nik, 'nama' => (string) $e->nama, 'alasan' => 'rumus error: '.$ex->getMessage()];
                        }
                    }
                }

                DB::table('pay_run_reference_versions')->where('run_id', $run->id)->delete();
                foreach ($versiReferensi as $tabel => $versi) {
                    DB::table('pay_run_reference_versions')->insert(['run_id' => $run->id, 'reference_table_id' => $tabel, 'reference_version_id' => $versi]);
                }

                $run->forceFill(['skipped_employees' => $skip, 'job_status' => 'idle', 'job_finished_at' => now()])->save();
            } catch (Throwable $e) {
                $run->forceFill(['job_status' => 'failed', 'job_error' => mb_substr($e->getMessage(), 0, 2000), 'job_finished_at' => now()])->save();
                throw $e;
            }
        });
    }
}
