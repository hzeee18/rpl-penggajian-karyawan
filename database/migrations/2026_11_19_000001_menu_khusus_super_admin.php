<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Keputusan pemilik (AUDIT.md §26): Manajemen Menu (tabel menus bersifat global) hanya untuk Super Admin.
 * Hak role perusahaan (company_id terisi) atas menu `menus` dicabut. Hak Super Admin tidak berubah.
 * MENGUBAH DATA privileges: backup DB dev sebelum dijalankan.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('privileges')
            ->where('menu_id', DB::table('menus')->where('url', 'menus')->value('id'))
            ->whereIn('role_id', DB::table('roles')->whereNotNull('company_id')->pluck('id'))
            ->update(['can_view' => false, 'can_create' => false, 'can_update' => false, 'can_delete' => false, 'can_approve' => false, 'updated_at' => now()]);
    }

    /** Kembalikan hak bawaan lama: role Admin tiap perusahaan vcud atas Manajemen Menu. */
    public function down(): void
    {
        DB::table('privileges')
            ->where('menu_id', DB::table('menus')->where('url', 'menus')->value('id'))
            ->whereIn('role_id', DB::table('roles')->whereNotNull('company_id')->where('nama', 'Admin')->pluck('id'))
            ->update(['can_view' => true, 'can_create' => true, 'can_update' => true, 'can_delete' => true, 'updated_at' => now()]);
    }
};
