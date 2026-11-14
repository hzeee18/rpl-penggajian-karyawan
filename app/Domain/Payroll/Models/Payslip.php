<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Company\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Slip gaji payroll baru: terbit setelah Manager menyetujui; isi dari snapshot run. */
final class Payslip extends Model
{
    use BelongsToCompany;

    protected $table = 'pay_payslips';

    protected $fillable = ['run_employee_id', 'employee_id', 'file_path', 'generated_at', 'note'];

    protected function casts(): array
    {
        return ['generated_at' => 'datetime'];
    }

    /** @return BelongsTo<RunEmployee, $this> */
    public function runEmployee(): BelongsTo
    {
        return $this->belongsTo(RunEmployee::class);
    }
}
