<?php

declare(strict_types=1);

use App\Domain\Payroll\Actions\TataMenu;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Keputusan pemilik (AUDIT.md §69): menu Kalender Payroll dipindah ke grup "Payroll" (bersama Master Payroll,
 * Generate Payroll, Approval). Hanya menyusun ulang tabel menus (parent_id, urutan); hak akses tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('menus')->where('url', 'pay-libur')->exists()) {
            app(TataMenu::class)->handle();
        }
    }

    public function down(): void
    {
        $konfigurasi = DB::table('menus')->whereNull('url')->whereNull('parent_id')->where('nama', 'Konfigurasi')->value('id');
        if ($konfigurasi !== null) {
            DB::table('menus')->where('url', 'pay-libur')->update(['parent_id' => $konfigurasi, 'urutan' => 4, 'updated_at' => now()]);
        }
    }
};
