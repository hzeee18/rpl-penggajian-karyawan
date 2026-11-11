<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Company\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Versi master payroll: variabel, parameter, item komponen. Draft bisa diubah; versi terbit dipakai payroll;
 * versi yang sudah dipakai payroll final (locked_at) tidak boleh diubah, hanya disalin ke versi baru.
 */
final class MasterVersion extends Model
{
    use BelongsToCompany;

    protected $table = 'pay_master_versions';

    protected $fillable = ['master_id', 'version_no', 'status', 'note', 'published_at', 'locked_at', 'created_by'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'locked_at' => 'datetime', 'version_no' => 'integer'];
    }

    /** @return BelongsTo<Master, $this> */
    public function master(): BelongsTo
    {
        return $this->belongsTo(Master::class);
    }

    /** @return HasMany<Variable, $this> */
    public function variables(): HasMany
    {
        return $this->hasMany(Variable::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<Param, $this> */
    public function params(): HasMany
    {
        return $this->hasMany(Param::class)->orderBy('code');
    }

    /** @return HasMany<Item, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class)->orderBy('sort_order')->orderBy('id');
    }

    public function bisaDiubah(): bool
    {
        return $this->status === 'draft' && $this->locked_at === null;
    }
}
