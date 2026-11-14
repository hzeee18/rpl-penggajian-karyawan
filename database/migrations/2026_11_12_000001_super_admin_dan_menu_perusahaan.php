<?php

declare(strict_types=1);

use App\Domain\Company\Actions\PasangHakPlatform;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 1d (multi-tenant): role platform Super Admin, menu global "Perusahaan", hak Super Admin,
 * dan "Pajak & Tarif TER" lihat-saja untuk HR. Isinya di PasangHakPlatform (idempoten; juga
 * dipanggil DatabaseSeeder untuk instalasi baru, karena di sana menu baru dibuat oleh seeder).
 *
 * Mengubah data privileges: backup DB dev sebelum dijalankan.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Hanya instalasi yang sudah berisi data. Instalasi baru: DatabaseSeeder / super-admin:buat
        // memanggil PasangHakPlatform setelah role & menu dibuat (urutan id role tetap sama).
        if (DB::table('roles')->exists()) {
            (new PasangHakPlatform)->handle();
        }
    }

    public function down(): void
    {
        $now = now();

        DB::table('privileges')
            ->whereIn('role_id', DB::table('roles')->where('nama', 'HR')->pluck('id'))
            ->where('menu_id', DB::table('menus')->where('url', 'pajak')->value('id'))
            ->update(['can_create' => true, 'can_update' => true, 'can_delete' => true, 'updated_at' => $now]);

        $roleId = DB::table('roles')->whereNull('company_id')->where('nama', PasangHakPlatform::SUPER_ADMIN)->value('id');
        $menuId = DB::table('menus')->where('url', PasangHakPlatform::MENU_PERUSAHAAN)->value('id');
        DB::table('privileges')->where('role_id', $roleId)->orWhere('menu_id', $menuId)->delete();
        DB::table('menus')->where('id', $menuId)->delete();
        // gagal (FK) bila masih ada user Super Admin: hapus/alihkan user itu dulu (disengaja)
        DB::table('roles')->where('id', $roleId)->delete();
    }
};
