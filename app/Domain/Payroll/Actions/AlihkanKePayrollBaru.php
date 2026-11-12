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
     * Role Karyawan: riwayat absensi, leave & lembur dari payroll baru digabung di menu Absensi (lihat saja),
     * sehingga menu Cuti dan Lembur lama ditutup untuk role ini.
     */
    public function hakKaryawan(int $companyId): void
    {
        $role = DB::table('roles')->where('company_id', $companyId)->where('nama', \App\Models\Role::KARYAWAN)->value('id');
        if ($role === null) {
            return;
        }
        $menus = DB::table('menus')->whereIn('url', ['absensi', 'cuti', 'lembur'])->pluck('id', 'url');
        foreach ($menus as $url => $menuId) {
            DB::table('privileges')->updateOrInsert(['role_id' => $role, 'menu_id' => $menuId], [
                'company_id' => $companyId, 'can_view' => $url === 'absensi',
                'can_create' => false, 'can_update' => false, 'can_delete' => false, 'can_approve' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
