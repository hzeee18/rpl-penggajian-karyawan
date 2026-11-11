<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Casts\Tanggal;
use App\Domain\Company\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Kalender libur perusahaan (dipakai WORKDAYS di rumus). */
final class Holiday extends Model
{
    use BelongsToCompany;

    protected $table = 'pay_holidays';

    protected $fillable = ['holiday_date', 'name'];

    protected function casts(): array
    {
        return ['holiday_date' => Tanggal::class];
    }
}
