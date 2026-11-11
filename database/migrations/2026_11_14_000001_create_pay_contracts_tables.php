<?php

declare(strict_types=1);

use App\Domain\Payroll\Actions\PasangMenuPayroll;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 (payroll baru): kontrak berversi + komponen kontrak (docs/schema.sql §3), prefix pay_.
 * - 1 karyawan hanya punya 1 kontrak aktif: kolom generated active_employee_id + unique.
 * - Pembaruan kontrak = baris baru (previous_contract_id), kontrak lama dinonaktifkan.
 * Juga memasang menu "Kontrak Payroll" (tanpa hak bawaan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_contracts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies');
            $t->foreignId('employee_id')->constrained('karyawan');
            $t->foreignId('previous_contract_id')->nullable()->constrained('pay_contracts');
            $t->string('contract_name', 191);
            $t->date('start_date');
            $t->date('end_date')->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('active_employee_id')->nullable()->storedAs('case when is_active = 1 then employee_id else null end');
            $t->decimal('info_thp', 15, 2)->nullable(); // informasi saja, bukan acuan hitung
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique('active_employee_id', 'uq_pay_contracts_one_active');
            $t->index(['company_id', 'employee_id'], 'idx_pay_contracts_employee');
        });

        Schema::create('pay_contract_components', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies');
            $t->foreignId('contract_id')->constrained('pay_contracts')->cascadeOnDelete();
            $t->foreignId('component_id')->constrained('pay_components');
            $t->decimal('amount', 15, 2)->default(0);
            $t->timestamps();
            $t->unique(['contract_id', 'component_id'], 'uq_pay_cc_contract_component');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE pay_contracts ADD CONSTRAINT ck_pay_contracts_dates CHECK (end_date IS NULL OR end_date >= start_date)');
        }

        if (DB::table('menus')->exists()) {
            (new PasangMenuPayroll)->handle();
        }
    }

    public function down(): void
    {
        $menu = DB::table('menus')->where('url', 'pay-kontrak')->value('id');
        if ($menu !== null) {
            DB::table('privileges')->where('menu_id', $menu)->delete();
            DB::table('menus')->where('id', $menu)->delete();
        }
        Schema::dropIfExists('pay_contract_components');
        Schema::dropIfExists('pay_contracts');
    }
};
