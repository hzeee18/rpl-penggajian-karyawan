<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Casts\Tanggal;
use App\Domain\Company\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Absensi payroll: satu baris = hadir pada tanggal itu (alpha dihitung dari hari kerja tanpa absensi/leave). */
final class Attendance extends Model
{
    use BelongsToCompany;

    protected $table = 'pay_attendances';

    protected $fillable = ['employee_id', 'attendance_date', 'check_in', 'check_out', 'late_hours', 'import_batch_id'];

    protected function casts(): array
    {
        return ['attendance_date' => Tanggal::class, 'late_hours' => 'decimal:2'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
