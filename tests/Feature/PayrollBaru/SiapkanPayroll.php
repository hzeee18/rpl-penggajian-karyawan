<?php

declare(strict_types=1);

namespace Tests\Feature\PayrollBaru;

use App\Domain\Payroll\Actions\BuatKontrak;
use App\Domain\Payroll\Actions\KelolaMasterPayroll;
use App\Domain\Payroll\Models\Attendance;
use App\Domain\Payroll\Models\Component;
use App\Domain\Payroll\Models\Employee;
use App\Domain\Payroll\Models\LeaveType;
use App\Domain\Payroll\Models\Master;
use App\Domain\Payroll\Models\Overtime;
use App\Domain\Payroll\Models\PeriodConfig;
use App\Domain\Payroll\Models\ReferenceTable;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

/**
 * Data payroll siap pakai untuk tes alur (perusahaan aktif = DEFAULT): katalog komponen, kontrak,
 * absensi periode 26 Okt - 25 Nov 2026, master "Payroll Usual" terbit. Tarif TER FIKTIF (bukan angka resmi).
 */
trait SiapkanPayroll
{
    /** @param list<string> $nama karyawan yang diberi kontrak */
    protected function siapkanPayroll(array $nama = ['Rizky Maulana', 'Nadia Putri']): Master
    {
        PeriodConfig::create(['start_day' => 26, 'end_day' => 25, 'start_in_previous_month' => true]);
        $c = [];
        foreach ([['gaji_pokok', 'earning', true, true], ['uang_makan', 'earning', true, true], ['lembur', 'earning', true, false],
            ['bpjs_tk', 'deduction', false, true], ['pph21', 'deduction', false, false]] as [$code, $type, $tax, $kontrak]) {
            $c[$code] = Component::create(['code' => $code, 'name' => ucwords(str_replace('_', ' ', $code)), 'type' => $type, 'is_taxable' => $tax, 'is_contractable' => $kontrak, 'default_amount' => '0']);
        }
        LeaveType::create(['code' => 'cuti', 'name' => 'Cuti']);

        $hari = collect(CarbonPeriod::create(CarbonImmutable::parse('2026-10-26'), CarbonImmutable::parse('2026-11-25')))->reject->isWeekend();
        foreach ($nama as $i => $n) {
            $e = Employee::where('nama', $n)->firstOrFail();
            app(BuatKontrak::class)->handle(['employee_id' => $e->id, 'contract_name' => 'PKWT', 'start_date' => '2026-01-01'], [
                $c['gaji_pokok']->id => (string) (5700000 + $i * 1000000), $c['uang_makan']->id => '660000', $c['bpjs_tk']->id => '250000',
            ]);
            foreach ($hari as $d) {
                Attendance::create(['employee_id' => $e->id, 'attendance_date' => $d->toDateString(), 'late_hours' => '0']);
            }
            Overtime::create(['employee_id' => $e->id, 'overtime_date' => '2026-11-07', 'duration_hours' => '2']);
        }

        $t = ReferenceTable::create(['code' => 'ter_uji', 'name' => 'TER uji (fiktif)']);
        $v = $t->versions()->create(['version_no' => 1, 'status' => 'published', 'published_at' => now()]);
        $v->rows()->create(['key_value' => null, 'range_from' => '0', 'range_to' => null, 'result_number' => '0.01']);

        $kelola = app(KelolaMasterPayroll::class);
        $master = $kelola->buatMaster(['code' => 'usual', 'name' => 'Payroll Usual']);
        $versi = $master->versions()->firstOrFail();
        $kelola->simpanParam($versi, ['code' => 'tarif_lembur', 'name' => 'Tarif lembur', 'value' => '25000']);
        $kelola->simpanVariabel($versi, ['code' => 'hadir', 'name' => 'Hadir', 'kind' => 'base', 'source' => 'attendances', 'aggregate' => 'count', 'sort_order' => 1]);
        $kelola->simpanVariabel($versi, ['code' => 'jam_lembur', 'name' => 'Jam lembur', 'kind' => 'base', 'source' => 'overtimes', 'aggregate' => 'sum', 'value_expression' => 'row.hours', 'sort_order' => 2]);
        $item = fn ($code, $calc, $f = null, $role = 'normal', $urut = 1) => $kelola->simpanItem($versi, ['component_id' => $c[$code]->id, 'code' => $code, 'calc_type' => $calc, 'formula' => $f, 'item_role' => $role, 'sort_order' => $urut]);
        $item('gaji_pokok', 'contract', null, 'normal', 1);
        $item('uang_makan', 'formula', "CONTRACT('uang_makan') / 22 * hadir", 'normal', 2);
        $item('lembur', 'formula', 'tarif_lembur * jam_lembur', 'normal', 3);
        $item('bpjs_tk', 'contract', null, 'normal', 4);
        $item('pph21', 'formula', "ROUND(gross_taxable * LOOKUP('ter_uji', '', gross_taxable))", 'pph21', 5);
        $kelola->terbitkan($versi);

        return $master;
    }
}
