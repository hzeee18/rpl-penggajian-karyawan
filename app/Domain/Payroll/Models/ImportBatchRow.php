<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Company\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Baris staging import (payload JSON). Diperbaiki / dihapus sebelum disimpan. */
final class ImportBatchRow extends Model
{
    use BelongsToCompany;

    protected $table = 'pay_import_batch_rows';

    public $timestamps = false;

    protected $fillable = ['batch_id', 'row_no', 'payload', 'is_valid', 'error_message'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'is_valid' => 'boolean'];
    }

    /** @return BelongsTo<ImportBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'batch_id');
    }
}
