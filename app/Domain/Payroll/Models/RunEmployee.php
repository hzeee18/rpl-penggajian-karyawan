<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Casts\Tanggal;
use App\Domain\Company\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Satu karyawan dalam run: snapshot data karyawan + total. is_blocking = 1 setelah generate
 * (unique key memastikan satu payroll aktif per karyawan per periode); 0 bila run ditolak.
 */
final class RunEmployee extends Model
{
    use BelongsToCompany;

    protected $table = 'pay_run_employees';

    protected $fillable = [
        'run_id', 'employee_id', 'contract_id', 'period_start', 'period_end', 'is_blocking',
        'employee_no', 'nik', 'full_name', 'department_name', 'position_name', 'ptkp_status', 'bank_name', 'bank_account_no',
        'variables_snapshot', 'total_earnings', 'total_deductions', 'gross_taxable', 'tax_deduction', 'take_home_pay',
    ];

    protected $hidden = ['bank_account_no'];

    protected function casts(): array
    {
        return [
            'period_start' => Tanggal::class, 'period_end' => Tanggal::class, 'is_blocking' => 'boolean',
            'bank_account_no' => 'encrypted', 'variables_snapshot' => 'array',
            'total_earnings' => 'decimal:2', 'total_deductions' => 'decimal:2', 'gross_taxable' => 'decimal:2', 'tax_deduction' => 'decimal:2', 'take_home_pay' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<PayrollRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'run_id');
    }

    /** @return HasMany<RunItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(RunItem::class, 'run_employee_id')->orderBy('sort_order')->orderBy('id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
