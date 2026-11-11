<?php

declare(strict_types=1);

use App\Domain\Payroll\Actions\PasangMenuPayroll;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 2 (payroll baru): menu global modul payroll baru, tanpa hak akses bawaan
 * (aplikasi lama tidak berubah sampai perusahaan mengaktifkan modul ini).
 * Instalasi baru: DatabaseSeeder memanggil PasangMenuPayroll setelah menu lama dibuat.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('menus')->exists()) {
            (new PasangMenuPayroll)->handle();
        }
    }

    public function down(): void
    {
        $urls = array_keys(PasangMenuPayroll::MENU);
        $ids = DB::table('menus')->whereIn('url', $urls)->pluck('id');
        DB::table('privileges')->whereIn('menu_id', $ids)->delete();
        DB::table('menus')->whereIn('id', $ids)->delete();
        $grup = DB::table('menus')->whereNull('url')->where('nama', PasangMenuPayroll::GRUP)->value('id');
        if ($grup !== null && ! DB::table('menus')->where('parent_id', $grup)->exists()) {
            DB::table('menus')->where('id', $grup)->delete();
        }
    }
};
