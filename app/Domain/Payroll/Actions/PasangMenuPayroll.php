<?php

declare(strict_types=1);

namespace App\Domain\Payroll\Actions;

use Illuminate\Support\Facades\DB;

/**
 * Menu modul payroll baru (global, idempoten) dan hak akses bawaannya.
 *
 * Menu dipasang berdampingan dengan modul lama TANPA hak akses bawaan, sehingga aplikasi lama
 * tidak berubah sampai perusahaan mengaktifkannya (`php artisan payroll:aktifkan {kode}`) atau
 * Admin memberi hak lewat Role & Privilege. Hak akses tetap diatur di tabel privileges.
 */
final class PasangMenuPayroll
{
    public const GRUP = 'Payroll Baru';

    /** url => [nama, ikon, hak bawaan per role (v c u d a)] */
    public const MENU = [
        'pay-karyawan' => ['Karyawan Payroll', 'id-card', ['HR' => 'vu', 'Admin' => 'v']],
        'pay-jabatan' => ['Jabatan', 'briefcase', ['Admin' => 'v']],
        'pay-libur' => ['Kalender Libur', 'calendar-days', ['HR' => 'vcud', 'Admin' => 'v']],
        'pay-tipe-leave' => ['Tipe Leave', 'tags', ['Admin' => 'v']],
        'pay-komponen' => ['Katalog Komponen', 'list-checks', ['HR' => 'vcud', 'Admin' => 'v']],
        'pay-cutoff' => ['Cut Off', 'calendar-range', ['Admin' => 'v']],
        // Fase 3
        'pay-kontrak' => ['Kontrak Payroll', 'file-signature', ['HR' => 'vcud', 'Admin' => 'v']],
        // Fase 4
        'pay-absensi' => ['Absensi Payroll', 'calendar-check', ['HR' => 'vcud', 'Admin' => 'v']],
        'pay-leave' => ['Leave Payroll', 'calendar-x', ['HR' => 'vcud', 'Admin' => 'v']],
        'pay-lembur' => ['Lembur Payroll', 'clock', ['HR' => 'vcud', 'Admin' => 'v']],
        'pay-import' => ['Import CSV', 'upload', ['HR' => 'vcud']],
        // Fase 5
        'pay-referensi' => ['Tabel Referensi', 'table-2', ['Admin' => 'v']],
        'pay-master' => ['Master Payroll', 'sigma', ['HR' => 'vcud', 'Admin' => 'v']],
        // Fase 6
        'pay-run' => ['Generate Payroll', 'calculator', ['HR' => 'vcud', 'Admin' => 'v']],
        'pay-approval' => ['Approval Payroll', 'circle-check', ['Manager' => 'va']],
        'pay-approver' => ['Approver Payroll', 'user-check', ['Admin' => 'vu']],
        // Fase 7
        'pay-slip' => ['Slip Gaji Payroll', 'file-text', ['Karyawan' => 'v', 'Manager' => 'v', 'HR' => 'v']],
    ];

    /**
     * Alamat halaman untuk url menu. Kunci hak akses modul ini memakai tanda hubung (`pay-karyawan`),
     * sedangkan halamannya berada di bawah prefix `/pay` (`pay/karyawan`). Url menu lain tidak berubah.
     */
    public static function alamat(string $url): string
    {
        return isset(self::MENU[$url]) ? 'pay/'.substr($url, 4) : $url;
    }

    /** Kebalikan alamat(): url menu dari segmen path `pay/{segmen}`, null bila bukan halaman modul ini. */
    public static function menuDariSegmen(?string $segmen): ?string
    {
        return $segmen !== null && isset(self::MENU['pay-'.$segmen]) ? 'pay-'.$segmen : null;
    }

    public function handle(): void
    {
        $now = now();
        // grup "Payroll Baru" diganti nama menjadi "Payroll" oleh TataMenu (AUDIT.md §35)
        $grup = DB::table('menus')->whereNull('url')->whereIn('nama', [self::GRUP, 'Payroll'])->orderBy('id')->value('id')
            ?? DB::table('menus')->insertGetId(['nama' => self::GRUP, 'url' => null, 'icon' => 'wallet-cards', 'urutan' => 90, 'created_at' => $now, 'updated_at' => $now]);

        $urutan = 0;
        foreach (self::MENU as $url => [$nama, $ikon]) {
            $urutan++;
            if (! DB::table('menus')->where('url', $url)->exists()) {
                DB::table('menus')->insert(['parent_id' => $grup, 'nama' => $nama, 'url' => $url, 'icon' => $ikon, 'urutan' => $urutan, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    /** Beri hak bawaan modul payroll ke role (berdasarkan nama) di satu perusahaan. */
    public function berikanHak(int $companyId): void
    {
        $this->handle();
        $now = now();
        $roles = DB::table('roles')->where('company_id', $companyId)->pluck('id', 'nama');
        $menus = DB::table('menus')->whereIn('url', array_keys(self::MENU))->pluck('id', 'url');

        foreach (self::MENU as $url => [, , $hakPerRole]) {
            foreach ($hakPerRole as $nama => $hak) {
                if (! isset($roles[$nama])) {
                    continue;
                }
                DB::table('privileges')->updateOrInsert(
                    ['role_id' => $roles[$nama], 'menu_id' => $menus[$url]],
                    [
                        'company_id' => $companyId,
                        'can_view' => str_contains($hak, 'v'), 'can_create' => str_contains($hak, 'c'), 'can_update' => str_contains($hak, 'u'),
                        'can_delete' => str_contains($hak, 'd'), 'can_approve' => str_contains($hak, 'a'),
                        'created_at' => $now, 'updated_at' => $now,
                    ],
                );
            }
        }
    }
}
