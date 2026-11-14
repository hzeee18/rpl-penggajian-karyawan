<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1e (multi-tenant): company_id menjadi NOT NULL setelah backfill diverifikasi (AGENTS.md).
 * Tetap nullable: users, roles, privileges (data platform Super Admin memakai company_id NULL).
 * Menolak berjalan bila masih ada baris company_id NULL.
 */
return new class extends Migration
{
    public const TABLES = [
        'karyawan', 'departemen', 'departemen_manager',
        'kontrak', 'kontrak_komponen',
        'attendance', 'leave', 'overtime',
        'notifikasi', 'approval', 'approval_mapping', 'log_aktivitas', 'import_excel',
        'payslip_component', 'master_payroll', 'payroll', 'payroll_detail', 'payslip',
    ];

    public function up(): void
    {
        $kosong = collect(self::TABLES)->mapWithKeys(fn ($t) => [$t => DB::table($t)->whereNull('company_id')->count()])->filter();
        if ($kosong->isNotEmpty()) {
            throw new RuntimeException('Masih ada company_id NULL: '.$kosong->map(fn ($n, $t) => "{$t}={$n}")->join(', '));
        }

        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->unsignedBigInteger('company_id')->nullable(false)->change());
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->unsignedBigInteger('company_id')->nullable()->change());
        }
    }
};
