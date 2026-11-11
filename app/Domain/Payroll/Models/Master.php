<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Company\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Master payroll ("Payroll Usual", "Payroll THR", ...). Isi rumus ada di versinya. */
final class Master extends Model
{
    use BelongsToCompany;

    protected $table = 'pay_masters';

    protected $fillable = ['code', 'name', 'description', 'current_version_id', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<MasterVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(MasterVersion::class, 'master_id')->orderByDesc('version_no');
    }

    /** @return BelongsTo<MasterVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(MasterVersion::class, 'current_version_id');
    }
}
