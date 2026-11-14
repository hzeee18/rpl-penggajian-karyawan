<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Payroll\Models\Contract;
use Illuminate\Support\Facades\DB;

/** Nonaktifkan kontrak secara manual (mis. kedaluwarsa atau karyawan resign). Data tidak dihapus. */
final class NonaktifkanKontrak
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Contract $contract): void
    {
        if (! $contract->is_active) {
            return;
        }

        DB::transaction(function () use ($contract) {
            $contract->forceFill(['is_active' => false])->save();
            $this->audit->log('deactivated', $contract, old: ['is_active' => true], new: ['is_active' => false]);
        });
    }
}
