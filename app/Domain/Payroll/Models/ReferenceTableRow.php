<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Company\Tenancy\BelongsToCompanyOrNational;
use Illuminate\Database\Eloquent\Model;

/** Baris tabel referensi: cocokkan key_value lalu nilai di antara range_from..range_to (NULL = tak terbatas). */
final class ReferenceTableRow extends Model
{
    use BelongsToCompanyOrNational;

    public $timestamps = false;

    protected $table = 'pay_reference_table_rows';

    protected $fillable = ['company_id', 'version_id', 'key_value', 'range_from', 'range_to', 'result_number', 'result_text', 'sort_order'];

    protected function casts(): array
    {
        return ['range_from' => 'decimal:2', 'range_to' => 'decimal:2', 'result_number' => 'decimal:6', 'sort_order' => 'integer'];
    }
}
