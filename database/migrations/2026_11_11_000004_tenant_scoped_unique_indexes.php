<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1a (multi-tenant): unique global pada data bertenant diganti unique per perusahaan.
 * users.username TETAP unik global (login tanpa memilih perusahaan).
 *
 * Catatan rollback: unique global lama dipasang kembali, jadi rollback GAGAL bila sudah ada
 * nilai yang sama di dua perusahaan (mis. kode departemen 'IT' di A dan B). Itu disengaja.
 */
return new class extends Migration
{
    /** tabel => [kolom, nama index lama, nama index baru] (kolom tenant: company_id) */
    private const BY_COMPANY_ID = [
        'departemen' => ['kode', 'departemen_kode_unique', 'uq_departemen_company_kode'],
        'karyawan' => ['nik', 'karyawan_nik_unique', 'uq_karyawan_company_nik'],
        'kontrak' => ['nomor', 'kontrak_nomor_unique', 'uq_kontrak_company_nomor'],
        'master_payroll' => ['periode', 'master_payroll_periode_unique', 'uq_master_payroll_company_periode'],
        'payroll' => ['periode', 'payroll_periode_unique', 'uq_payroll_company_periode'],
    ];

    public function up(): void
    {
        // roles memakai company_key (NULL -> 0) agar role platform juga unik.
        Schema::table('roles', function (Blueprint $t) {
            $t->dropUnique('roles_nama_unique');
            $t->unique(['company_key', 'nama'], 'uq_roles_company_nama');
        });

        foreach (self::BY_COMPANY_ID as $table => [$column, $old, $new]) {
            Schema::table($table, function (Blueprint $t) use ($column, $old, $new) {
                $t->dropUnique($old);
                $t->unique(['company_id', $column], $new);
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::BY_COMPANY_ID, true) as $table => [$column, $old, $new]) {
            // Di MySQL, index (company_id, kolom) mengambil alih index milik FK company_id,
            // jadi FK dilepas dulu lalu dipasang lagi (FK membuat index-nya sendiri).
            Schema::table($table, fn (Blueprint $t) => $t->dropForeign(['company_id']));
            Schema::table($table, function (Blueprint $t) use ($column, $old, $new) {
                $t->dropUnique($new);
                $t->unique($column, $old);
            });
            Schema::table($table, fn (Blueprint $t) => $t->foreign('company_id')->references('id')->on('companies'));
        }

        Schema::table('roles', function (Blueprint $t) {
            $t->dropUnique('uq_roles_company_nama');
            $t->unique('nama', 'roles_nama_unique');
        });
    }
};
