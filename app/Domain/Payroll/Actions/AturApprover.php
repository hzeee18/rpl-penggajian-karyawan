<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Company\Models\Company;
use App\Domain\Payroll\Models\PayrollApprover;
use App\Domain\Payroll\Run\PenyetujuPayroll;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Setelan Approver Payroll per perusahaan (AUDIT.md §47, §50, §77), dipakai `ApproverController`:
 * password slip, pakai approval atau tidak, dan penyetuju (role dan/atau user). Perusahaan selalu dari user login.
 */
final class AturApprover
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function simpanSlip(Company $company, bool $pakaiPassword): string
    {
        $lama = $company->only(['slip_pakai_password']);
        $company->forceFill(['slip_pakai_password' => $pakaiPassword])->save();
        $this->audit->log('updated', $company, old: $lama, new: $company->only(['slip_pakai_password']));

        return $pakaiPassword ? 'Slip gaji yang terbit berikutnya memakai password.' : 'Slip gaji yang terbit berikutnya tanpa password.';
    }

    /**
     * Bila approval dipakai, wajib minimal satu role atau user penyetuju; hanya role/user perusahaan ini (aktif) yang bisa dipilih.
     *
     * @param  list<int>  $roleIds
     * @param  list<int>  $userIds
     */
    public function simpanApproval(Company $company, bool $butuh, array $roleIds, array $userIds): string
    {
        $roleIds = array_values(array_unique(array_map('intval', $roleIds)));
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if (array_diff($roleIds, PenyetujuPayroll::roleKandidat()->pluck('id')->all()) !== []) {
            throw ValidationException::withMessages(['penyetuju' => 'Role yang dipilih tidak ditemukan.']);
        }
        if (array_diff($userIds, PenyetujuPayroll::userKandidat()->pluck('id')->all()) !== []) {
            throw ValidationException::withMessages(['penyetuju' => 'User yang dipilih tidak aktif atau tidak ditemukan.']);
        }
        if ($butuh && $roleIds === [] && $userIds === []) {
            throw ValidationException::withMessages(['penyetuju' => 'Payroll perlu persetujuan: pilih minimal satu role atau user penyetuju.']);
        }
        $lama = ['payroll_butuh_approval' => $company->payroll_butuh_approval, 'penyetuju' => PayrollApprover::get(['user_id', 'role_id'])->toArray()];
        DB::transaction(function () use ($company, $butuh, $roleIds, $userIds) {
            $company->forceFill(['payroll_butuh_approval' => $butuh])->save();
            PenyetujuPayroll::simpan($roleIds, $userIds);
        });
        $this->audit->log('updated', $company, old: $lama, new: ['payroll_butuh_approval' => $butuh, 'penyetuju' => ['role_id' => $roleIds, 'user_id' => $userIds]]);

        return $butuh ? 'Payroll yang di-generate sekarang perlu disetujui penyetuju yang dipilih.' : 'Payroll yang di-generate sekarang langsung final tanpa approval.';
    }
}
