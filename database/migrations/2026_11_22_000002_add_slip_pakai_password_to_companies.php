<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keputusan pemilik (AUDIT.md §50): Admin perusahaan menentukan apakah PDF slip gaji diberi password.
 * Bawaan true = perilaku sekarang (password: 2 digit terakhir nomor karyawan + 4 digit terakhir rekening).
 * false = PDF terbit tanpa password, termasuk untuk karyawan yang nomor rekeningnya belum lengkap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->boolean('slip_pakai_password')->default(true)->after('payroll_butuh_approval');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn('slip_pakai_password');
        });
    }
};
