<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Domain\Company\Tenancy\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penyesuaian manual HR di payroll draft (bonus, THR, reimburse, koreksi potongan, ...), AUDIT.md §52.
 * Jenis = komponen Katalog; pajak mengikuti flag komponen. Nominal selalu >= 0 (arah dari tipe komponen).
 */
final class RunAdjustment extends Model
{
    use BelongsToCompany;

    protected $table = 'pay_run_adjustments';

    protected $fillable = ['run_id', 'employee_id', 'component_id', 'amount', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    /** @return BelongsTo<Component, $this> */
    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Kode baris di rincian run: satu baris per komponen penyesuaian per karyawan. */
    public static function kodeItem(int $componentId): string
    {
        return 'adj_'.$componentId;
    }
}
