<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Engine;

use App\Domain\Payroll\Models\Attendance;
use App\Domain\Payroll\Models\Contract;
use App\Domain\Payroll\Models\Employee;
use App\Domain\Payroll\Models\Holiday;
use App\Domain\Payroll\Models\Leave;
use App\Domain\Payroll\Models\Overtime;
use Carbon\CarbonImmutable;

/**
 * Sumber data variabel base. Setiap sumber hanya membuka field yang di-whitelist (row.<field>);
 * data diambil dengan query Eloquent terparameter (tidak ada SQL dari input HR).
 */
final class SumberData
{
    /** sumber => [field => keterangan] */
    public const FIELD = [
        'attendances' => ['date' => 'tanggal hadir', 'weekday' => '1=Senin ... 7=Minggu', 'is_holiday' => 'hari libur perusahaan', 'check_in' => 'jam masuk HH:MM', 'check_out' => 'jam keluar HH:MM', 'late_hours' => 'jam terlambat'],
        'leaves' => ['date' => 'tanggal leave', 'weekday' => '1-7', 'is_holiday' => 'hari libur', 'type' => 'kode tipe leave'],
        'overtimes' => ['date' => 'tanggal lembur', 'weekday' => '1-7', 'is_holiday' => 'hari libur', 'hours' => 'durasi jam'],
        'holidays' => ['date' => 'tanggal libur', 'weekday' => '1-7'],
        'employees' => ['join_date' => 'tanggal masuk', 'resign_date' => 'tanggal resign', 'ptkp' => 'status PTKP', 'has_npwp' => 'punya NPWP', 'department_code' => 'kode departemen', 'position_code' => 'kode jabatan'],
        'contracts' => ['start_date' => 'mulai kontrak', 'end_date' => 'akhir kontrak', 'name' => 'nama kontrak'],
    ];

    /** @var array<string, true>|null */
    private ?array $libur = null;

    public function __construct(
        private readonly Employee $employee,
        private readonly ?Contract $contract,
        private readonly CarbonImmutable $mulai,
        private readonly CarbonImmutable $selesai,
    ) {}

    /** @return array<string, true> tanggal libur perusahaan dalam periode */
    public function libur(): array
    {
        return $this->libur ??= Holiday::whereBetween('holiday_date', [$this->mulai->toDateString(), $this->selesai->toDateString()])
            ->pluck('holiday_date')->mapWithKeys(fn ($d) => [$d->toDateString() => true])->all();
    }

    /** @return list<array<string, mixed>> baris dengan field whitelist */
    public function baris(string $sumber): array
    {
        $rentang = [$this->mulai->toDateString(), $this->selesai->toDateString()];
        $hari = fn ($d) => ['date' => $d->toDateString(), 'weekday' => (int) $d->isoWeekday(), 'is_holiday' => isset($this->libur()[$d->toDateString()])];
        $emp = (int) $this->employee->id;

        return match ($sumber) {
            'attendances' => Attendance::where('employee_id', $emp)->whereBetween('attendance_date', $rentang)->orderBy('attendance_date')->get()
                ->map(fn ($a) => $hari($a->attendance_date) + [
                    'check_in' => $a->check_in ? substr($a->check_in, 0, 5) : '', 'check_out' => $a->check_out ? substr($a->check_out, 0, 5) : '',
                    'late_hours' => (string) $a->late_hours,
                ])->all(),
            'leaves' => Leave::with('leaveType:id,code')->where('employee_id', $emp)->whereBetween('leave_date', $rentang)->orderBy('leave_date')->get()
                ->map(fn ($l) => $hari($l->leave_date) + ['type' => $l->leaveType->code])->all(),
            'overtimes' => Overtime::where('employee_id', $emp)->whereBetween('overtime_date', $rentang)->orderBy('overtime_date')->get()
                ->map(fn ($o) => $hari($o->overtime_date) + ['hours' => (string) $o->duration_hours])->all(),
            'holidays' => array_map(fn ($t) => ['date' => $t, 'weekday' => (int) CarbonImmutable::parse($t)->isoWeekday()], array_keys($this->libur())),
            'employees' => [[
                'join_date' => $this->employee->tgl_masuk?->toDateString() ?? '', 'resign_date' => $this->employee->resign_date?->toDateString() ?? '',
                'ptkp' => (string) $this->employee->ptkpStatus(), 'has_npwp' => (bool) $this->employee->has_npwp,
                'department_code' => (string) $this->employee->departemen?->kode, 'position_code' => (string) $this->employee->position?->code,
            ]],
            'contracts' => $this->contract === null ? [] : [[
                'start_date' => $this->contract->start_date->toDateString(), 'end_date' => $this->contract->end_date?->toDateString() ?? '',
                'name' => $this->contract->contract_name,
            ]],
            default => throw new RumusException("Sumber data tidak dikenal: {$sumber}"),
        };
    }
}
