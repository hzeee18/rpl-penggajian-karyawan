<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Company\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Variabel rumus. base = agregasi baris sumber (source + filter_expression + value_expression + aggregate)
 * dalam periode; derived = rumus dari variabel/parameter di atasnya (sort_order).
 */
final class Variable extends Model
{
    use BelongsToCompany;

    protected $table = 'pay_variables';

    protected $fillable = ['master_version_id', 'code', 'name', 'kind', 'source', 'filter_expression', 'value_expression', 'aggregate', 'formula', 'sort_order'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }
}
