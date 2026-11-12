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

            $this->hakPeran($companyId);
            app(TataMenu::class)->handle();
            // hak Super Admin untuk menu master payroll (menu dibuat oleh berikanHak di atas)
            app(\App\Domain\Company\Actions\PasangHakPlatform::class)->handle();
        });
    }

    /**
     * Hak menu per role setelah beralih (keputusan pemilik, AUDIT.md §32-§34). url => hak (v c u d a), '' = ditutup.
     * - Karyawan: riwayat sendiri dari payroll baru di menu Absensi (lihat saja); Cuti & Lembur lama ditutup.
     * - Manager: Dashboard, Approval Payroll, Slip Gaji; Absensi/Cuti/Lembur lama ditutup.
     * - HR: Data Karyawan, Absensi/Leave/Lembur/Import CSV payroll, Master Payroll, Generate Payroll, Slip Gaji
     *   (milik sendiri), Kontrak, Katalog Komponen, Kalender Libur, Manajemen User. Menu lama dan data master
     *   yang dikelola Super Admin (Departemen, Jabatan, Tipe Leave, Cut Off, Tabel Referensi) ditutup.
     */
    public const HAK_PERAN = [
        \App\Models\Role::KARYAWAN => ['absensi' => 'v', 'cuti' => '', 'lembur' => ''],
        \App\Models\Role::MANAGER => ['absensi' => '', 'cuti' => '', 'lembur' => ''],
        \App\Models\Role::HR => [
            'absensi' => '', 'cuti' => '', 'lembur' => '', 'import' => '', 'pajak' => '', 'master-payroll' => '', 'departemen' => '',
            'pay-jabatan' => '', 'pay-tipe-leave' => '', 'pay-cutoff' => '', 'pay-referensi' => '',
            'users' => 'vcud',
        ],
    ];

    /** Admin: semua menu perusahaan terbuka (keputusan pemilik, AUDIT.md §35). url => hak; selain ini 'vcud'. */
    public const HAK_ADMIN_KHUSUS = ['dashboard' => 'v', 'pay-slip' => 'v', 'pay-approval' => 'va', 'pay-cutoff' => 'vu'];

    /** @return array<string, string> url => hak untuk role Admin: semua menu di grup TataMenu kecuali Modul Lama & menu platform */
    public static function hakAdmin(): array
    {
        $urls = ['dashboard', 'pay-slip'];
        foreach (TataMenu::GRUP as $grup => [, , $menu]) {
            if ($grup !== 'Modul Lama') {
                array_push($urls, ...array_keys($menu));
            }
        }
        $urls = array_diff($urls, [...\App\Domain\Company\Actions\PasangHakPlatform::MENU_PLATFORM, 'menus']);

        return collect($urls)->mapWithKeys(fn ($u) => [$u => self::HAK_ADMIN_KHUSUS[$u] ?? 'vcud'])->all();
    }

    public function hakPeran(int $companyId): void
    {
        $semua = self::HAK_PERAN + [\App\Models\Role::ADMIN => self::hakAdmin()];
        $menus = DB::table('menus')->whereIn('url', collect($semua)->flatMap(fn ($h) => array_keys($h))->unique()->all())->pluck('id', 'url');
        foreach ($semua as $nama => $daftar) {
            $role = DB::table('roles')->where('company_id', $companyId)->where('nama', $nama)->value('id');
            if ($role === null) {
                continue;
            }
            foreach ($daftar as $url => $hak) {
                if (! isset($menus[$url])) {
                    continue;
                }
                DB::table('privileges')->updateOrInsert(['role_id' => $role, 'menu_id' => $menus[$url]], [
                    'company_id' => $companyId,
                    'can_view' => str_contains($hak, 'v'), 'can_create' => str_contains($hak, 'c'), 'can_update' => str_contains($hak, 'u'),
                    'can_delete' => str_contains($hak, 'd'), 'can_approve' => str_contains($hak, 'a'),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }
}
