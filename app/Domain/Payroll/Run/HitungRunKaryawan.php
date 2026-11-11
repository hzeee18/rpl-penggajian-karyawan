<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Run;

use App\Domain\Payroll\Engine\Kalkulator;
use App\Domain\Payroll\Models\Contract;
use App\Domain\Payroll\Models\Employee;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\RunEmployee;
use Illuminate\Support\Facades\DB;

/**
 * Hitung satu karyawan di run dan simpan snapshot + rincian komponen.
 * Sel yang di-override dipertahankan (hitung ulang tidak menimpa) dan nilainya mengalir ke komponen sesudahnya.
 */
final class HitungRunKaryawan
{
    /** @var array<int, Kalkulator> kalkulator per versi master (dipakai ulang dalam satu proses) */
    private array $kalkulator = [];

    /** @return array<int, int> reference_table_id => version_id yang dipakai */
    public function handle(PayrollRun $run, Employee $employee, Contract $contract): array
    {
        $runEmployee = RunEmployee::where('run_id', $run->id)->where('employee_id', $employee->id)->first();
        $override = $runEmployee === null ? [] : $runEmployee->items()->where('is_overridden', true)->pluck('final_amount', 'code')->map(fn ($v) => (string) $v)->all();

        $kalkulator = $this->kalkulator[(int) $run->master_version_id] ??= new Kalkulator($run->masterVersion);
        $hasil = $kalkulator->hitung($employee, $contract, $run->period_start->toImmutable(), $run->period_end->toImmutable(), $override);
        $employee->loadMissing(['departemen', 'position', 'pajak']);

        DB::transaction(function () use ($run, $employee, $contract, $hasil, &$runEmployee) {
            $runEmployee ??= new RunEmployee(['run_id' => $run->id, 'employee_id' => $employee->id]);
            $runEmployee->fill([
                'contract_id' => $contract->id,
                'period_start' => $run->period_start, 'period_end' => $run->period_end,
                'employee_no' => $employee->employeeNo(), 'nik' => (string) $employee->nik, 'full_name' => (string) $employee->nama,
                'department_name' => $employee->departemen?->nama, 'position_name' => $employee->position?->name ?? $employee->jabatan,
                'ptkp_status' => (string) $employee->ptkpStatus(), 'bank_name' => $employee->nama_bank,
                'bank_account_no' => $employee->bank_account_no, // snapshot tetap terenkripsi (cast encrypted)
                'variables_snapshot' => $hasil->variabel,
                'total_earnings' => $hasil->totalPenambah, 'total_deductions' => $hasil->totalPengurang,
                'gross_taxable' => $hasil->brutoKenaPajak, 'take_home_pay' => $hasil->thp,
            ])->save();

            $kode = array_column($hasil->item, 'code');
            $runEmployee->items()->whereNotIn('code', $kode)->delete();
            foreach ($hasil->item as $i) {
                $runEmployee->items()->updateOrCreate(['code' => $i['code']], [
                    'payroll_item_id' => $i['item_id'], 'component_id' => $i['component_id'], 'name' => $i['name'], 'type' => $i['type'],
                    'is_taxable' => $i['is_taxable'], 'sort_order' => $i['sort_order'],
                    'calculated_amount' => $i['calculated'], 'final_amount' => $i['final'], 'is_overridden' => $i['overridden'],
                ]);
            }
        });

        return $hasil->versiReferensi;
    }
}
