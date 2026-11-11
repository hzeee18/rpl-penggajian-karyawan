<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Company\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Katalog komponen gaji. Hanya dinonaktifkan. Uang: decimal string, bukan float. */
final class Component extends Model
{
    use BelongsToCompany;

    protected $table = 'pay_components';

    protected $fillable = ['code', 'name', 'type', 'is_taxable', 'is_contractable', 'default_amount', 'is_active'];

    protected function casts(): array
    {
        return ['is_taxable' => 'boolean', 'is_contractable' => 'boolean', 'default_amount' => 'decimal:2', 'is_active' => 'boolean'];
    }
}
