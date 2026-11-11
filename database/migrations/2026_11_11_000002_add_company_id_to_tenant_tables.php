<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1a (multi-tenant): kolom company_id NULLABLE + FK di semua tabel bertenant.
 * Hanya menambah kolom; tidak ada data yang diubah (backfill di migration berikutnya).
 *
 * Tidak bertenant:
 * - menus                              : global (docs/schema.sql)
 * - golongan_ter, pajak, tarif_ter     : tabel nasional
 * - sessions, cache, jobs, migrations… : infrastruktur Laravel
 *
 * company_id pada users, roles, privileges akan tetap boleh NULL (level platform / Super Admin).
 */
return new class extends Migration
{
    public const TABLES = [
        'users', 'roles', 'privileges',
        'karyawan', 'departemen', 'departemen_manager',
        'kontrak', 'kontrak_komponen',
        'attendance', 'leave', 'overtime',
        'notifikasi', 'approval', 'approval_mapping', 'log_aktivitas', 'import_excel',
        'payslip_component', 'master_payroll', 'payroll', 'payroll_detail', 'payslip',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('company_id')->nullable()->after('id')->constrained('companies');
            });
        }

        // Kunci unik role per perusahaan; NULL (role platform) dipetakan ke 0. Dipakai di migration unique.
        // VIRTUAL (bukan STORED seperti schema.sql) karena kolom STORED tidak bisa ditambah lewat ALTER di SQLite.
        Schema::table('roles', function (Blueprint $t) {
            $t->unsignedBigInteger('company_key')->virtualAs('ifnull(company_id, 0)')->after('company_id');
        });
    }

    public function down(): void
    {
        // company_key bergantung pada company_id, jadi dihapus lebih dulu.
        Schema::table('roles', fn (Blueprint $t) => $t->dropColumn('company_key'));

        foreach (array_reverse(self::TABLES) as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId('company_id'));
        }
    }
};
