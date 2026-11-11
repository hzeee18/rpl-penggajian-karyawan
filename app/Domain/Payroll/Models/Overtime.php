<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Casts\Tanggal;
use App\Domain\Company\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lembur payroll: total jam per tanggal (hari apa pun). */
final class Overtime extends Model
{
    use BelongsToCompany;

    protected $table = 'pay_overtimes';

    protected $fillable = ['employee_id', 'overtime_date', 'duration_hours', 'note', 'import_batch_id'];

    protected function casts(): array
    {
        return ['overtime_date' => Tanggal::class, 'duration_hours' => 'decimal:2'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
