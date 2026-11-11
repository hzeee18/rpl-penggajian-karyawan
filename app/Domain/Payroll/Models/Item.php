<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Company\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item komponen di versi master. type/is_taxable disalin dari katalog saat item dibuat.
 * calc_type contract = nilai kontrak (0 bila tidak ada); formula = hasil rumus.
 * item_role pph21 selalu dievaluasi paling akhir.
 */
final class Item extends Model
{
    use BelongsToCompany;

    protected $table = 'pay_items';

    protected $fillable = ['master_version_id', 'component_id', 'code', 'name', 'type', 'is_taxable', 'calc_type', 'formula', 'item_role', 'sort_order'];

    protected function casts(): array
    {
        return ['is_taxable' => 'boolean', 'sort_order' => 'integer'];
    }

    /** @return BelongsTo<Component, $this> */
    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class);
    }
}
