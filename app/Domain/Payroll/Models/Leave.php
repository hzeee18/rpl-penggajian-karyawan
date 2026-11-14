<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Casts\Tanggal;
use App\Domain\Company\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Leave payroll: satu baris = satu hari kerja leave (rentang dipecah saat input/import; Sabtu-Minggu dilewati). */
final class Leave extends Model
{
    use BelongsToCompany;

    protected $table = 'pay_leaves';

    protected $fillable = ['employee_id', 'leave_type_id', 'leave_date', 'note', 'import_batch_id'];

    protected function casts(): array
    {
        return ['leave_date' => Tanggal::class];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<LeaveType, $this> */
    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }
}
