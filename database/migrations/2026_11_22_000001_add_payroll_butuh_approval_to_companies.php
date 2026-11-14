<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keputusan pemilik (AUDIT.md §47): Admin perusahaan menentukan apakah payroll yang di-generate butuh
 * persetujuan Manager. Bawaan true = perilaku sekarang (draft -> pending_approval -> approved/rejected).
 * false = setelah generate payroll langsung approved (riwayat pajak dicatat dan slip diterbitkan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->boolean('payroll_butuh_approval')->default(true)->after('payroll_baru');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn('payroll_butuh_approval');
        });
    }
};
