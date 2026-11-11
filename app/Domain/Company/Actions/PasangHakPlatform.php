<?php

declare(strict_types=1);

namespace App\Domain\Company\Actions;

use Illuminate\Support\Facades\DB;

/**
 * Data platform (idempoten, boleh dipanggil berulang): role Super Admin, menu global "Perusahaan",
 * hak akses Super Admin, dan menu "Pajak & Tarif TER" lihat-saja untuk HR (tabel nasional).
 *
 * Dipanggil oleh: migration 2026_11_12_000001 (instalasi berjalan), DatabaseSeeder (instalasi baru,
 * setelah menu dibuat), dan perintah super-admin:buat. Memakai DB::table dengan company_id NULL
 * secara sengaja: role dan hak Super Admin adalah data platform, bukan milik perusahaan.
 */
final class PasangHakPlatform
{
    public const SUPER_ADMIN = 'Super Admin';

    public const MENU_PERUSAHAAN = 'perusahaan';

    /** Menu khusus Super Admin: tidak bisa diberikan ke role perusahaan dan tidak tampil di Manajemen Menu perusahaan. */
    public const MENU_PLATFORM = [self::MENU_PERUSAHAAN];

    /** url menu => hak (v c u d a). Menu yang belum ada dilewati. */
    private const HAK_SUPER_ADMIN = [
        'dashboard' => 'v', 'perusahaan' => 'vcud', 'pajak' => 'vcud', 'users' => 'vcud', 'roles' => 'vcud', 'menus' => 'vcud',
    ];

    public function handle(): int
    {
        $now = now();

        $roleId = DB::table('roles')->whereNull('company_id')->where('nama', self::SUPER_ADMIN)->value('id')
            ?? DB::table('roles')->insertGetId(['company_id' => null, 'nama' => self::SUPER_ADMIN, 'created_at' => $now, 'updated_at' => $now]);

        if (! DB::table('menus')->where('url', self::MENU_PERUSAHAAN)->exists()) {
            DB::table('menus')->insert([
                'nama' => 'Perusahaan', 'url' => self::MENU_PERUSAHAAN, 'icon' => 'building', 'urutan' => 100,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        foreach (DB::table('menus')->whereIn('url', array_keys(self::HAK_SUPER_ADMIN))->pluck('id', 'url') as $url => $menuId) {
            $hak = self::HAK_SUPER_ADMIN[$url];
            DB::table('privileges')->updateOrInsert(
                ['role_id' => $roleId, 'menu_id' => $menuId],
                [
                    'company_id' => null,
                    'can_view' => str_contains($hak, 'v'), 'can_create' => str_contains($hak, 'c'), 'can_update' => str_contains($hak, 'u'),
                    'can_delete' => str_contains($hak, 'd'), 'can_approve' => str_contains($hak, 'a'),
                    'created_at' => $now, 'updated_at' => $now,
                ],
            );
        }

        // HR semua perusahaan: Pajak & Tarif TER hanya lihat (keputusan AUDIT.md §11 butir 2)
        $pajak = DB::table('menus')->where('url', 'pajak')->value('id');
        if ($pajak !== null) {
            DB::table('privileges')
                ->whereIn('role_id', DB::table('roles')->where('nama', 'HR')->pluck('id'))
                ->where('menu_id', $pajak)
                ->update(['can_create' => false, 'can_update' => false, 'can_delete' => false, 'updated_at' => $now]);
        }

        // Manajemen Menu (global) hanya Super Admin: cabut hak role perusahaan (keputusan AUDIT.md §26)
        $menuMenus = DB::table('menus')->where('url', 'menus')->value('id');
        if ($menuMenus !== null) {
            DB::table('privileges')->where('menu_id', $menuMenus)
                ->whereIn('role_id', DB::table('roles')->whereNotNull('company_id')->pluck('id'))
                ->update(['can_view' => false, 'can_create' => false, 'can_update' => false, 'can_delete' => false, 'can_approve' => false, 'updated_at' => $now]);
        }

        return (int) $roleId;
    }
}
