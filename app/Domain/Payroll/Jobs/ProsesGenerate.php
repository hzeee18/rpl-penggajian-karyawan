<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Jobs;

use App\Domain\Audit\AuditLogger;
use App\Domain\Company\Tenancy\CurrentCompany;
use App\Domain\Payroll\Engine\RumusException;
use App\Domain\Payroll\Models\Contract;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\ReferenceTableVersion;
use App\Domain\Payroll\Models\RunEmployee;
use App\Domain\Payroll\Run\HitungRunKaryawan;
use App\Domain\Payroll\Run\PemilihKaryawan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Generate payroll (final): hitung ulang dengan data terbaru (override dipertahankan), kunci karyawan
 * per periode (is_blocking = 1, dijaga unique key), status pending_approval, kunci versi master dan
 * versi tabel referensi yang dipakai. Setelah ini data run tidak bisa diubah.
 */
final class ProsesGenerate implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 0;

    public function __construct(public readonly int $runId, public readonly int $companyId, public readonly ?int $userId) {}

    public function handle(CurrentCompany $konteks, PemilihKaryawan $pemilih, HitungRunKaryawan $hitung, AuditLogger $audit): void
    {
        $konteks->runAs($this->companyId, function () use ($pemilih, $hitung, $audit) {
            $run = PayrollRun::with(['masterVersion', 'creator'])->findOrFail($this->runId);
            if (! $run->isDraft()) {
                return;
            }
            $run->forceFill(['job_status' => 'running', 'job_started_at' => now(), 'job_error' => null])->save();

            try {
                $skip = $run->skipped_employees ?? [];
                $masihBebas = $pemilih->pilih($run)['ikut']->pluck('employee.id')->flip();
                $versiReferensi = [];

                foreach ($run->employees()->with('employee')->get() as $re) {
                    $alasan = null;
                    $kontrak = Contract::active()->where('employee_id', $re->employee_id)->first();
                    if (! $masihBebas->has($re->employee_id) || $kontrak === null) {
                        $alasan = $kontrak === null ? 'tidak punya kontrak aktif' : 'sudah punya payroll aktif di periode ini';
                    } else {
                        try {
                            $versiReferensi = $hitung->handle($run, $re->employee, $kontrak) + $versiReferensi;
                            // penjaga utama payroll ganda: unique key (employee, periode, blocking_key)
                            RunEmployee::whereKey($re->id)->update(['is_blocking' => true]);
                        } catch (RumusException $e) {
                            $alasan = 'rumus error: '.$e->getMessage();
                        } catch (QueryException) {
                            $alasan = 'sudah punya payroll aktif di periode ini';
                        }
                    }
                    if ($alasan !== null) {
                        $skip[] = ['employee_id' => (int) $re->employee_id, 'nik' => $re->nik, 'nama' => $re->full_name, 'alasan' => $alasan];
                        $re->delete();
                    }
                }

                if (! $run->employees()->exists()) {
                    // bukan error sistem: simpan alasan per karyawan agar HR tahu kenapa, run tetap draft
                    $run->forceFill([
                        'skipped_employees' => array_values($skip), 'job_status' => 'failed', 'job_finished_at' => now(),
                        'job_error' => 'Tidak ada karyawan yang bisa digenerate. Lihat daftar karyawan dilewati di bawah.',
                    ])->save();

                    return;
                }

                DB::transaction(function () use ($run, $skip, $versiReferensi, $audit) {
                    DB::table('pay_run_reference_versions')->where('run_id', $run->id)->delete();
                    foreach ($versiReferensi as $tabel => $versi) {
                        DB::table('pay_run_reference_versions')->insert(['run_id' => $run->id, 'reference_table_id' => $tabel, 'reference_version_id' => $versi]);
                    }
                    // versi yang dipakai payroll final dikunci: hanya bisa disalin ke versi baru
                    $run->masterVersion->forceFill(['locked_at' => $run->masterVersion->locked_at ?? now()])->save();
                    ReferenceTableVersion::whereIn('id', array_values($versiReferensi))->whereNull('locked_at')->update(['locked_at' => now()]);

                    $run->forceFill([
                        'status' => PayrollRun::PENDING, 'generated_at' => now(), 'skipped_employees' => array_values($skip),
                        'approver_user_id' => $run->creator?->approver_user_id, 'job_status' => 'idle', 'job_finished_at' => now(),
                    ])->save();
                    $audit->log('generated', $run, new: ['karyawan' => $run->employees()->count(), 'skip' => count($skip), 'oleh' => $this->userId]);
                });
            } catch (Throwable $e) {
                // gagal: kembalikan penanda blocking agar karyawan tidak terkunci oleh run yang gagal
                RunEmployee::where('run_id', $run->id)->update(['is_blocking' => false]);
                $run->forceFill(['job_status' => 'failed', 'job_error' => mb_substr($e->getMessage(), 0, 2000), 'job_finished_at' => now()])->save();
                throw $e;
            }
        });
    }
}
