<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Company\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Rincian komponen per karyawan per run. calculated_amount = hasil rumus; final_amount = nilai akhir.
 * Baris dengan payroll_item_id NULL dan kode adj_{component_id} = penyesuaian HR (pay_run_adjustments, AUDIT.md §52).
 * is_overridden hanya ada di data lama (fitur ubah sel sudah diganti penyesuaian).
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
