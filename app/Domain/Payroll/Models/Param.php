<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Company\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Parameter master (tarif lembur, pembagi, konstanta) yang diisi HR. */
final class Param extends Model
{
    use BelongsToCompany;

    protected $table = 'pay_params';

    protected $fillable = ['master_version_id', 'code', 'name', 'value'];

    protected function casts(): array
    {
        return ['value' => 'decimal:4'];
    }
}
