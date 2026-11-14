<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Company\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Satu file CSV yang diunggah: previewing -> committed | cancelled. */
final class ImportBatch extends Model
{
    use BelongsToCompany;

    protected $table = 'pay_import_batches';

    protected $fillable = ['type', 'file_name', 'total_rows', 'valid_rows', 'status', 'created_by', 'committed_at'];

    protected function casts(): array
    {
        return ['committed_at' => 'datetime', 'total_rows' => 'integer', 'valid_rows' => 'integer'];
    }

    /** @return HasMany<ImportBatchRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(ImportBatchRow::class, 'batch_id')->orderBy('row_no');
    }
}
