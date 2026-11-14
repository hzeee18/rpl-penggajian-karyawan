<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pencatatan iuran pengurang pajak (JHT/JP/pensiun yang dibayar karyawan) untuk PPh21 masa Desember / resign:
 * - pay_components.is_tax_deductible : penanda di katalog komponen (pengurang yang mengurangi penghasilan neto).
 * - pay_items.is_tax_deductible      : disalin dari katalog saat item master dibuat (seperti is_taxable).
 * - pay_run_employees.tax_deduction  : jumlah iuran pengurang pajak hasil hitung (snapshot run).
 * - pay_tax_monthly_histories.tax_deduction : riwayat per bulan (ditulis saat payroll disetujui).
 * Hanya menambah kolom dengan nilai bawaan; data lama tidak diubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pay_components', fn (Blueprint $t) => $t->boolean('is_tax_deductible')->default(false)->after('is_taxable'));
        Schema::table('pay_items', fn (Blueprint $t) => $t->boolean('is_tax_deductible')->default(false)->after('is_taxable'));
        Schema::table('pay_run_employees', fn (Blueprint $t) => $t->decimal('tax_deduction', 15, 2)->default(0)->after('gross_taxable'));
        Schema::table('pay_tax_monthly_histories', fn (Blueprint $t) => $t->decimal('tax_deduction', 15, 2)->default(0)->after('gross_taxable'));
    }

    public function down(): void
    {
        Schema::table('pay_tax_monthly_histories', fn (Blueprint $t) => $t->dropColumn('tax_deduction'));
        Schema::table('pay_run_employees', fn (Blueprint $t) => $t->dropColumn('tax_deduction'));
        Schema::table('pay_items', fn (Blueprint $t) => $t->dropColumn('is_tax_deductible'));
        Schema::table('pay_components', fn (Blueprint $t) => $t->dropColumn('is_tax_deductible'));
    }
};
