<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Run;

use App\Domain\Payroll\Models\PayrollApprover;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Siapa yang boleh menyetujui/menolak payroll (keputusan pemilik, AUDIT.md §77): DIATUR SENDIRI di menu Approver Payroll,
 * berupa user tertentu dan/atau role tertentu (tabel pay_payroll_approvers). Tidak ada syarat tersembunyi dari jabatan,
 * departemen, atau hak menu: akun aktif yang dipilih (langsung atau lewat role-nya) otomatis bisa membuka menu Approval dan
 * menyetujui/menolak. Siapa pun yang lebih dulu memutuskan, itu yang dicatat. Default: tidak ada penyetuju.
 */
final class PenyetujuPayroll
{
    public static function boleh(?User $user): bool
    {
        if ($user === null || ! $user->is_active || $user->company_id === null) {
            return false;
        }

        return PayrollApprover::where(fn ($q) => $q->where('user_id', $user->id)->orWhere('role_id', $user->role_id))->exists();
    }

    /** @return Collection<int, User> penyetuju aktif perusahaan (gabungan user terpilih dan anggota role terpilih) */
    public static function daftar(): Collection
    {
        $pilihan = PayrollApprover::get(['user_id', 'role_id']);
        if ($pilihan->isEmpty()) {
            return collect();
        }

        return User::with(['karyawan', 'role'])->where('is_active', true)
            ->where(fn ($q) => $q->whereIn('id', $pilihan->pluck('user_id')->filter())->orWhereIn('role_id', $pilihan->pluck('role_id')->filter()))
            ->orderBy('username')->get();
    }

    /** Semua role perusahaan bisa dipilih sebagai penyetuju. */
    public static function roleKandidat(): Collection
    {
        return Role::orderBy('nama')->get();
    }

    /** Semua user aktif perusahaan bisa dipilih sebagai penyetuju. */
    public static function userKandidat(): Collection
    {
        return User::with(['karyawan', 'role'])->where('is_active', true)->orderBy('username')->get();
    }

    /**
     * Simpan pilihan penyetuju (mengganti pilihan lama).
     *
     * @param  list<int>  $roleIds
     * @param  list<int>  $userIds
     */
    public static function simpan(array $roleIds, array $userIds): void
    {
        PayrollApprover::query()->delete();
        foreach (array_unique($roleIds) as $id) {
            PayrollApprover::create(['role_id' => $id]);
        }
        foreach (array_unique($userIds) as $id) {
            PayrollApprover::create(['user_id' => $id]);
        }
    }
}
