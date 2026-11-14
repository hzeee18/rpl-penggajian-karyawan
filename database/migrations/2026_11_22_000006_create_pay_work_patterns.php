<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keputusan pemilik (AUDIT.md §55): hari kerja tidak lagi dikunci Senin-Jumat.
 * - pay_work_patterns: pola hari kerja per perusahaan (hari ISO 1=Senin ... 7=Minggu), satu boleh jadi bawaan.
 * - departemen.pay_work_pattern_id dan karyawan.pay_work_pattern_id (pengecualian per karyawan), keduanya nullable.
 *   Urutan: pola karyawan -> pola departemen -> pola bawaan perusahaan -> Senin-Jumat.
 * - pay_holidays.departemen_id: NULL = libur semua departemen (perilaku lama), terisi = libur khusus departemen itu.
 *   Unique lama (company_id, holiday_date) diganti (company_id, holiday_date, departemen_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_work_patterns', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies');
            $t->string('code', 64);
            $t->string('name', 191);
            $t->string('work_days', 20); // mis. "1,2,3,4,5"
            $t->boolean('is_default')->default(false);
            $t->timestamps();
            $t->unique(['company_id', 'code'], 'uq_pay_work_patterns_company_code');
        });

        Schema::table('departemen', function (Blueprint $t) {
            $t->foreignId('pay_work_pattern_id')->nullable()->constrained('pay_work_patterns')->nullOnDelete();
        });
        Schema::table('karyawan', function (Blueprint $t) {
            $t->foreignId('pay_work_pattern_id')->nullable()->constrained('pay_work_patterns')->nullOnDelete();
        });

        Schema::table('pay_holidays', function (Blueprint $t) {
            $t->foreignId('departemen_id')->nullable()->after('holiday_date')->constrained('departemen')->cascadeOnDelete();
            $t->unique(['company_id', 'holiday_date', 'departemen_id'], 'uq_pay_holidays_company_date_dept');
        });
        Schema::table('pay_holidays', function (Blueprint $t) {
            $t->dropUnique('uq_pay_holidays_company_date');
        });
    }

    public function down(): void
    {
        Schema::table('pay_holidays', function (Blueprint $t) {
            $t->unique(['company_id', 'holiday_date'], 'uq_pay_holidays_company_date'); // gagal bila sudah ada libur khusus departemen di tanggal yang sama
        });
        Schema::table('pay_holidays', function (Blueprint $t) {
            $t->dropUnique('uq_pay_holidays_company_date_dept');
            $t->dropConstrainedForeignId('departemen_id');
        });
        Schema::table('karyawan', fn (Blueprint $t) => $t->dropConstrainedForeignId('pay_work_pattern_id'));
        Schema::table('departemen', fn (Blueprint $t) => $t->dropConstrainedForeignId('pay_work_pattern_id'));
        Schema::dropIfExists('pay_work_patterns');
    }
};
