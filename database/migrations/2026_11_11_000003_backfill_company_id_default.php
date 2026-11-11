<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 1a (multi-tenant): isi company_id semua data lama dengan perusahaan DEFAULT
 * (keputusan AGENTS.md / AUDIT.md §9 butir 3). Hanya baris yang company_id-nya masih NULL.
 *
 * WAJIB backup DB dev sebelum migration ini dijalankan (mengubah data lama).
 */
return new class extends Migration
{
    /** Sama dengan 2026_11_11_000002_add_company_id_to_tenant_tables::TABLES. */
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
        $companyId = DB::table('companies')->where('code', 'DEFAULT')->value('id');
        if ($companyId === null) {
            throw new RuntimeException('Perusahaan DEFAULT tidak ditemukan; jalankan migration create_companies_table dulu.');
        }

        DB::transaction(function () use ($companyId) {
            foreach (self::TABLES as $table) {
                DB::table($table)->whereNull('company_id')->update(['company_id' => $companyId]);
            }
        });
    }

    /** Kembalikan ke kondisi sebelum backfill (kolom tetap ada; dihapus oleh rollback migration sebelumnya). */
    public function down(): void
    {
        $companyId = DB::table('companies')->where('code', 'DEFAULT')->value('id');
        if ($companyId === null) {
            return;
        }

        DB::transaction(function () use ($companyId) {
            foreach (self::TABLES as $table) {
                DB::table($table)->where('company_id', $companyId)->update(['company_id' => null]);
            }
        });
    }
};
