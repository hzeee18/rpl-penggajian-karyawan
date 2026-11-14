<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 (payroll baru): kolom karyawan yang dibutuhkan payroll (docs/schema.sql employees),
 * ditambahkan ke tabel lama `karyawan` (keputusan AUDIT.md §9 butir 1). Kolom lama tidak diubah.
 *
 * Pemetaan: employee_no & nik (kunci CSV) = karyawan.nik; ptkp_status = pajak.status_ptkp (pajak_id);
 * full_name = nama; join_date = tgl_masuk; bank_name = nama_bank.
 * bank_account_no TERENKRIPSI, diisi dari no_rekening (teks biasa milik modul lama) dan dijaga sinkron
 * oleh PayrollServiceProvider setiap no_rekening berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('karyawan', function (Blueprint $t) {
            $t->foreignId('position_id')->nullable()->after('jabatan')->constrained('pay_positions');
            $t->date('resign_date')->nullable()->after('tgl_masuk');
            $t->boolean('has_npwp')->default(false)->after('pajak_id');
            $t->text('npwp')->nullable()->after('has_npwp');
            $t->text('bank_account_no')->nullable()->after('no_rekening');
        });

        foreach (DB::table('karyawan')->whereNotNull('no_rekening')->where('no_rekening', '!=', '')->get(['id', 'no_rekening']) as $k) {
            DB::table('karyawan')->where('id', $k->id)->update(['bank_account_no' => Crypt::encryptString((string) $k->no_rekening)]);
        }
    }

    public function down(): void
    {
        Schema::table('karyawan', function (Blueprint $t) {
            $t->dropConstrainedForeignId('position_id');
            $t->dropColumn(['resign_date', 'has_npwp', 'npwp', 'bank_account_no']);
        });
    }
};
