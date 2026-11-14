<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keputusan pemilik (AUDIT.md §77): penyetuju payroll diatur sendiri per perusahaan di menu Approver Payroll,
 * berupa user tertentu dan/atau role tertentu (tidak lagi otomatis "Manager di departemen HR").
 * Satu baris = satu user ATAU satu role. Tanpa backfill (keputusan pemilik): tidak ada penyetuju bawaan; perusahaan yang
 * memakai approval wajib memilih penyetuju dulu sebelum generate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_payroll_approvers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies');
            $t->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $t->foreignId('role_id')->nullable()->constrained('roles')->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'user_id'], 'uq_pay_approvers_company_user');
            $t->unique(['company_id', 'role_id'], 'uq_pay_approvers_company_role');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_payroll_approvers');
    }
};
