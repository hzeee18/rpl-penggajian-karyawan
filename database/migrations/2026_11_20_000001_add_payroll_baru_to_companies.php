<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keputusan pemilik (AUDIT.md §29): modul payroll baru diterima. Saklar per perusahaan `payroll_baru`:
 * bila aktif, form Data Karyawan memuat data payroll, menu Kontrak/Proses Payroll/Riwayat/Slip/Komponen lama
 * dialihkan ke modul baru, dan Profil/Dashboard/cek lembur membaca kontrak baru.
 * Hanya menambah kolom (bawaan false = perilaku lama). Pengaktifan per perusahaan lewat `php artisan payroll:aktifkan {kode}`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->boolean('payroll_baru')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn('payroll_baru');
        });
    }
};
