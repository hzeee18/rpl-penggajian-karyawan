<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Models;

use App\Casts\Tanggal;
use App\Domain\Company\Tenancy\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Payroll run. status: draft -> pending_approval -> approved | rejected (tidak ada "Dibayar", fase 2).
 * job_status menandai proses background (preview / generate). Draft dihapus fisik; selain draft tidak bisa dihapus.
 */
final class PayrollRun extends Model
{
    use BelongsToCompany;

    public const DRAFT = 'draft';

    public const PENDING = 'pending_approval';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $table = 'pay_runs';

    protected $fillable = [
        'title', 'period_start', 'period_end', 'master_version_id', 'employee_scope', 'employee_selection', 'status',
        'job_type', 'job_status', 'job_error', 'job_started_at', 'job_finished_at', 'skipped_employees',
        'created_by', 'approver_user_id', 'generated_at', 'approved_at', 'rejected_at', 'reject_reason',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => Tanggal::class, 'period_end' => Tanggal::class,
            'employee_selection' => 'array', 'skipped_employees' => 'array',
            'job_started_at' => 'datetime', 'job_finished_at' => 'datetime', 'generated_at' => 'datetime',
            'approved_at' => 'datetime', 'rejected_at' => 'datetime',
        ];
    }

    /** @return HasMany<RunEmployee, $this> */
    public function employees(): HasMany
    {
        return $this->hasMany(RunEmployee::class, 'run_id');
    }

    /** @return BelongsTo<MasterVersion, $this> */
    public function masterVersion(): BelongsTo
    {
        return $this->belongsTo(MasterVersion::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function sedangDiproses(): bool
    {
        return in_array($this->job_status, ['queued', 'running'], true);
    }
}
