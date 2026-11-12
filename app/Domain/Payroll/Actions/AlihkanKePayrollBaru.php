<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Actions;

use App\Domain\Company\Models\Company;
use App\Domain\Payroll\ModulPayroll;
use Illuminate\Support\Facades\DB;

/**
 * Aktifkan modul payroll baru untuk SATU perusahaan (idempoten):
 * hak bawaan menu payroll baru, saklar companies.payroll_baru, dan hak menu lama yang digantikan dicabut
 * (menu tersembunyi; data dan kode lama tidak dihapus). Perusahaan lain tidak berubah.
 */
final class AlihkanKePayrollBaru
{
    public function __construct(private readonly PasangMenuPayroll $menu) {}

    public function handle(int $companyId): void
    {
        DB::transaction(function () use ($companyId) {
            $this->menu->berikanHak($companyId);
            Company::whereKey($companyId)->update(['payroll_baru' => true]);

            DB::table('privileges')
                ->whereIn('menu_id', DB::table('menus')->whereIn('url', array_keys(ModulPayroll::MENU_DIGANTIKAN))->pluck('id'))
                ->whereIn('role_id', DB::table('roles')->where('company_id', $companyId)->pluck('id'))
                ->update(['can_view' => false, 'can_create' => false, 'can_update' => false, 'can_delete' => false, 'can_approve' => false, 'updated_at' => now()]);

            $this->hakKaryawan($companyId);
        });
    }

    /**
     * Menu lama yang datanya dari tabel lama (absensi/cuti/lembur) untuk role non-HR, per keputusan pemilik:
     * - Karyawan: riwayat sendiri dari payroll baru digabung di menu Absensi (lihat saja); Cuti & Lembur ditutup.
     * - Manager: memakai Dashboard, Approval Payroll, dan Slip Gaji; Absensi/Cuti/Lembur lama ditutup.
     * Role HR (dan Manager HR yang bertindak sebagai HR) tidak diubah di sini.
     */
    public const HAK_MENU_LAMA = [
        \App\Models\Role::KARYAWAN => ['absensi' => true, 'cuti' => false, 'lembur' => false],
        \App\Models\Role::MANAGER => ['absensi' => false, 'cuti' => false, 'lembur' => false],
    ];

    public function hakKaryawan(int $companyId): void
    {
        $menus = DB::table('menus')->whereIn('url', ['absensi', 'cuti', 'lembur'])->pluck('id', 'url');
        foreach (self::HAK_MENU_LAMA as $nama => $hak) {
            $role = DB::table('roles')->where('company_id', $companyId)->where('nama', $nama)->value('id');
            if ($role === null) {
                continue;
            }
            foreach ($hak as $url => $lihat) {
                if (! isset($menus[$url])) {
                    continue;
                }
                DB::table('privileges')->updateOrInsert(['role_id' => $role, 'menu_id' => $menus[$url]], [
                    'company_id' => $companyId, 'can_view' => $lihat,
                    'can_create' => false, 'can_update' => false, 'can_delete' => false, 'can_approve' => false,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }
}
