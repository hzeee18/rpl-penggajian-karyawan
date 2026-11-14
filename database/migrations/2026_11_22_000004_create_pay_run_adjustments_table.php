<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keputusan pemilik (AUDIT.md §52): "ubah sel" diganti "Tambah penyesuaian". HR menambah baris penyesuaian
 * (jenis dari Katalog Komponen, nominal >= 0, keterangan wajib) per karyawan di payroll draft. Penyesuaian
 * dihitung sebelum PPh21 sesuai flag komponen (kena pajak / pengurang pajak) dan tampil terpisah di approval & slip.
 * Disimpan per run + karyawan (bukan per baris preview) supaya tetap ada saat preview diulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_run_adjustments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies');
            $t->foreignId('run_id')->constrained('pay_runs')->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained('karyawan');
            $t->foreignId('component_id')->constrained('pay_components');
            $t->decimal('amount', 15, 2);
            $t->string('note', 255);
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->index(['run_id', 'employee_id'], 'idx_pay_run_adjustments_run_employee');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_run_adjustments');
    }
};
