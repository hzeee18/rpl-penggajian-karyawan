<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keputusan pemilik (AUDIT.md §53): menu "Kalender Libur" jadi "Kalender Payroll" dengan dua tab.
 * - Periode: tabel pay_periods, satu baris per bulan payroll, dibuat dari aturan Cut Off dan selalu menyambung
 *   (mulai = selesai periode sebelumnya + 1). Tanggal selesai bisa diubah selama periode belum dipakai payroll.
 * - Hari Libur: pay_holidays ditambah kolom jenis (nasional / cuti bersama / perusahaan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_periods', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies');
            $t->char('period_month', 7); // Y-m
            $t->date('start_date');
            $t->date('end_date');
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique(['company_id', 'period_month'], 'uq_pay_periods_company_month');
        });

        Schema::table('pay_holidays', function (Blueprint $t) {
            $t->string('type', 20)->default('nasional')->after('holiday_date');
        });

        DB::table('menus')->where('url', 'pay-libur')->update(['nama' => 'Kalender Payroll']);
    }

    public function down(): void
    {
        DB::table('menus')->where('url', 'pay-libur')->update(['nama' => 'Kalender Libur']);
        Schema::table('pay_holidays', function (Blueprint $t) {
            $t->dropColumn('type');
        });
        Schema::dropIfExists('pay_periods');
    }
};
