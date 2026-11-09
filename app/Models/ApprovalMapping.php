<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalMapping extends Model
{
    public const TIPE = ['manager_divisi' => 'Manager divisi karyawan', 'manager_hr' => 'Manager HR'];

    protected $table = 'approval_mapping';
    protected $fillable = ['jenis_ref', 'level', 'tipe_approver'];

    public function approvals()
    {
        return $this->hasMany(Approval::class);
    }

    /**
     * Semua user yang boleh memutuskan (bisa lebih dari satu manager). Siapa pun yang duluan memutuskan, itu yang dipakai.
     * Kontrak: manager departemen karyawan (selain dirinya); kalau tidak ada, naik ke Manager HR. Payroll: semua Manager HR.
     */
    public function cariApprovers(?Karyawan $karyawan = null): \Illuminate\Support\Collection
    {
        $akun = fn ($daftar) => collect($daftar)->reject(fn ($m) => $karyawan && $m->id === $karyawan->id)
            ->map(fn ($m) => $m->user)->filter(fn ($u) => $u && $u->is_active && $u->isRole(Role::MANAGER))->values();
        $managerHr = fn () => $akun(Departemen::with('managers.user')->where('kode', Departemen::KODE_HR)->first()?->managers ?? []);

        if ($this->tipe_approver === 'manager_hr') {
            return $managerHr();
        }
        $divisi = $akun($karyawan?->departemen?->managers()->with('user')->get() ?? []);

        return $divisi->isNotEmpty() ? $divisi : $managerHr();
    }

    public function cariApprover(?Karyawan $karyawan = null): ?User
    {
        return $this->cariApprovers($karyawan)->first();
    }
}
