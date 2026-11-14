<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Company\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Jabatan (master payroll). Hanya dinonaktifkan, tidak dihapus. */
final class Position extends Model
{
    use BelongsToCompany;

    protected $table = 'pay_positions';

    protected $fillable = ['code', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
