<?php

namespace App\Models;

use App\Domain\Company\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot departemen_manager (multi-tenant). Dipakai lewat Departemen::managers()->using(...)
 * agar sync()/attach() mengisi company_id otomatis seperti model lain.
 */
class DepartemenManager extends Pivot
{
    use BelongsToCompany;

    protected $table = 'departemen_manager';

    public $incrementing = true;
}
