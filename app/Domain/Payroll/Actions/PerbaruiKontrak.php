<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Payroll\Models\Contract;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Perbarui kontrak = data baru: kontrak lama dinonaktifkan, kontrak baru dibuat dengan
 * previous_contract_id dan nilai komponen yang disalin (boleh diubah HR).
 */
final class PerbaruiKontrak
{
    public function __construct(private readonly BuatKontrak $buat, private readonly AuditLogger $audit) {}

    /**
     * @param  array{contract_name: string, start_date: string, end_date?: ?string, info_thp?: ?string}  $data
     * @param  array<int, string|null>|null  $nilai  null = salin semua nilai komponen kontrak lama
     */
    public function handle(Contract $lama, array $data, ?array $nilai = null): Contract
    {
        return DB::transaction(function () use ($lama, $data, $nilai): Contract {
            $lama = Contract::lockForUpdate()->findOrFail($lama->id);
            if (! $lama->is_active) {
                throw ValidationException::withMessages(['contract' => 'Hanya kontrak aktif yang bisa diperbarui.']);
            }

            $nilai ??= $lama->components()->pluck('amount', 'component_id')->map(fn ($a) => (string) $a)->all();

            $lama->forceFill(['is_active' => false])->save();
            $this->audit->log('deactivated', $lama, old: ['is_active' => true], new: ['is_active' => false, 'alasan' => 'diperbarui']);

            return $this->buat->handle($data + ['employee_id' => (int) $lama->employee_id], $nilai, (int) $lama->id);
        });
    }
}
