<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Company\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Rincian komponen per karyawan per run. calculated_amount = hasil rumus; final_amount = nilai akhir
 * (bisa hasil edit/override). Override tidak tertimpa hitung ulang. Edit sel TIDAK dicatat di audit log.
 */
final class RunItem extends Model
{
    use BelongsToCompany;

    protected $table = 'pay_run_items';

    protected $fillable = [
        'run_employee_id', 'payroll_item_id', 'component_id', 'code', 'name', 'type', 'is_taxable', 'sort_order',
        'calculated_amount', 'final_amount', 'is_overridden',
    ];

    protected function casts(): array
    {
        return ['is_taxable' => 'boolean', 'is_overridden' => 'boolean', 'calculated_amount' => 'decimal:2', 'final_amount' => 'decimal:2', 'sort_order' => 'integer'];
    }
}
