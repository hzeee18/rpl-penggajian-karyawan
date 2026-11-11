<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Company\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Tipe leave (cuti, izin, sakit, ... bisa ditambah HR). */
final class LeaveType extends Model
{
    use BelongsToCompany;

    protected $table = 'pay_leave_types';

    protected $fillable = ['code', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
