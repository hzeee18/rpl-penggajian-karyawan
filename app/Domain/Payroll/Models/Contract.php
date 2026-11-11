<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Casts\Tanggal;
use App\Domain\Company\Tenancy\BelongsToCompany;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kontrak payroll berversi. Tidak pernah diubah di tempat: pembaruan = kontrak baru
 * (previous_contract_id), kontrak lama dinonaktifkan. Hanya 1 kontrak aktif per karyawan.
 */
final class Contract extends Model
{
    use BelongsToCompany;

    protected $table = 'pay_contracts';

    protected $fillable = ['employee_id', 'previous_contract_id', 'contract_name', 'start_date', 'end_date', 'is_active', 'info_thp', 'created_by'];

    protected function casts(): array
    {
        return [
            'start_date' => Tanggal::class,
            'end_date' => Tanggal::class,
            'is_active' => 'boolean',
            'info_thp' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<Contract, $this> */
    public function previous(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_contract_id');
    }

    /** @return HasMany<ContractComponent, $this> */
    public function components(): HasMany
    {
        return $this->hasMany(ContractComponent::class);
    }

    /** @param  Builder<Contract>  $q */
    public function scopeActive(Builder $q): void
    {
        $q->where('is_active', true);
    }

    /** Masih aktif tetapi tanggal akhirnya sudah lewat (dinonaktifkan manual oleh HR). */
    public function isExpired(?\DateTimeInterface $per = null): bool
    {
        return $this->is_active && $this->end_date !== null && $this->end_date->lt(($per ? Carbon::instance($per) : now())->startOfDay());
    }
}
