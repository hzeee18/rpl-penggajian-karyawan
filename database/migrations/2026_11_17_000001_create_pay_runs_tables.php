<?php

declare(strict_types=1);

use App\Domain\Payroll\Actions\PasangMenuPayroll;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 6 (payroll baru): payroll run, preview, approval (docs/schema.sql §7), prefix pay_.
 * - users.approver_user_id (keputusan AUDIT.md §9 butir 5): isi awal user HR -> Manager HR perusahaan yang sama
 *   (manager departemen HRD yang punya akun aktif; bila lebih dari satu, id user terkecil). MENGUBAH DATA users.
 * - Unique uq_pre_one_active_per_period: satu payroll aktif (is_blocking) per karyawan per periode.
 * Juga memasang menu Generate Payroll, Approval Payroll, Approver Payroll (tanpa hak bawaan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->foreignId('approver_user_id')->nullable()->after('karyawan_id')->constrained('users')->nullOnDelete());

        Schema::create('pay_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies');
            $t->string('title', 191);
            $t->date('period_start');
            $t->date('period_end');
            $t->foreignId('master_version_id')->nullable()->constrained('pay_master_versions');
            $t->enum('employee_scope', ['all', 'department', 'individual'])->nullable();
            $t->json('employee_selection')->nullable();
            $t->enum('status', ['draft', 'pending_approval', 'approved', 'rejected'])->default('draft');
            $t->enum('job_type', ['preview', 'generate'])->nullable();
            $t->enum('job_status', ['idle', 'queued', 'running', 'failed'])->default('idle');
            $t->text('job_error')->nullable();
            $t->timestamp('job_started_at')->nullable();
            $t->timestamp('job_finished_at')->nullable();
            $t->json('skipped_employees')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('approver_user_id')->nullable()->constrained('users');
            $t->timestamp('generated_at')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('rejected_at')->nullable();
            $t->string('reject_reason', 500)->nullable();
            $t->timestamps();
            $t->index(['company_id', 'period_start', 'period_end'], 'idx_pay_runs_company_period');
            $t->index(['approver_user_id', 'status'], 'idx_pay_runs_approver_status');
        });

        Schema::create('pay_run_employees', function (Blueprint $t) {
            $t->id();
            $t->foreignId('run_id')->constrained('pay_runs')->cascadeOnDelete();
            $t->foreignId('company_id')->constrained('companies');
            $t->foreignId('employee_id')->constrained('karyawan');
            $t->foreignId('contract_id')->constrained('pay_contracts');
            $t->date('period_start');
            $t->date('period_end');
            $t->boolean('is_blocking')->default(false);
            $t->unsignedTinyInteger('blocking_key')->nullable()->storedAs('case when is_blocking = 1 then 1 else null end');
            $t->string('employee_no', 32);
            $t->string('nik', 32);
            $t->string('full_name', 191);
            $t->string('department_name', 191)->nullable();
            $t->string('position_name', 191)->nullable();
            $t->string('ptkp_status', 10);
            $t->string('bank_name', 100)->nullable();
            $t->text('bank_account_no')->nullable(); // snapshot terenkripsi
            $t->json('variables_snapshot')->nullable();
            $t->decimal('total_earnings', 15, 2)->default(0);
            $t->decimal('total_deductions', 15, 2)->default(0);
            $t->decimal('gross_taxable', 15, 2)->default(0);
            $t->decimal('take_home_pay', 15, 2)->default(0);
            $t->timestamps();
            $t->unique(['run_id', 'employee_id'], 'uq_pre_run_employee');
            $t->unique(['employee_id', 'period_start', 'period_end', 'blocking_key'], 'uq_pre_one_active_per_period');
            $t->index('employee_id', 'idx_pre_employee');
        });

        Schema::create('pay_run_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies');
            $t->foreignId('run_employee_id')->constrained('pay_run_employees')->cascadeOnDelete();
            $t->foreignId('payroll_item_id')->nullable()->constrained('pay_items')->nullOnDelete();
            $t->foreignId('component_id')->nullable()->constrained('pay_components')->nullOnDelete();
            $t->string('code', 64);
            $t->string('name', 191);
            $t->enum('type', ['earning', 'deduction']);
            $t->boolean('is_taxable')->default(false);
            $t->integer('sort_order')->default(0);
            $t->decimal('calculated_amount', 15, 2)->default(0);
            $t->decimal('final_amount', 15, 2)->default(0);
            $t->boolean('is_overridden')->default(false);
            $t->timestamps();
            $t->unique(['run_employee_id', 'code'], 'uq_pri_run_employee_code');
        });

        Schema::create('pay_run_reference_versions', function (Blueprint $t) {
            $t->foreignId('run_id')->constrained('pay_runs')->cascadeOnDelete();
            $t->foreignId('reference_table_id')->constrained('pay_reference_tables');
            $t->foreignId('reference_version_id')->constrained('pay_reference_table_versions');
            $t->primary(['run_id', 'reference_table_id']);
        });

        Schema::create('pay_tax_monthly_histories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies');
            $t->foreignId('employee_id')->constrained('karyawan');
            $t->foreignId('run_id')->constrained('pay_runs')->cascadeOnDelete();
            $t->unsignedSmallInteger('tax_year');
            $t->unsignedTinyInteger('tax_month');
            $t->string('ter_category', 8)->nullable();
            $t->decimal('ter_rate', 8, 6)->nullable();
            $t->decimal('gross_taxable', 15, 2)->default(0);
            $t->decimal('pph21', 15, 2)->default(0);
            $t->timestamps();
            $t->unique(['employee_id', 'run_id'], 'uq_pay_tmh_employee_run');
            $t->index(['employee_id', 'tax_year', 'tax_month'], 'idx_pay_tmh_employee_year');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE pay_runs ADD CONSTRAINT ck_pay_runs_period CHECK (period_end >= period_start)');
        }

        // isi awal approver: user HR -> Manager HR (manager departemen HRD) di perusahaan yang sama
        foreach (DB::table('users')->join('roles', 'roles.id', '=', 'users.role_id')->where('roles.nama', 'HR')->whereNotNull('users.company_id')
            ->get(['users.id', 'users.company_id']) as $hr) {
            $managerHr = DB::table('users')
                ->join('roles', 'roles.id', '=', 'users.role_id')
                ->join('departemen_manager', 'departemen_manager.karyawan_id', '=', 'users.karyawan_id')
                ->join('departemen', 'departemen.id', '=', 'departemen_manager.departemen_id')
                ->where('roles.nama', 'Manager')->where('departemen.kode', 'HRD')
                ->where('users.is_active', true)->where('users.company_id', $hr->company_id)->where('departemen.company_id', $hr->company_id)
                ->min('users.id');
            if ($managerHr !== null) {
                DB::table('users')->where('id', $hr->id)->whereNull('approver_user_id')->update(['approver_user_id' => $managerHr]);
            }
        }

        if (DB::table('menus')->exists()) {
            (new PasangMenuPayroll)->handle();
        }
    }

    public function down(): void
    {
        $menus = DB::table('menus')->whereIn('url', ['pay-run', 'pay-approval', 'pay-approver'])->pluck('id');
        DB::table('privileges')->whereIn('menu_id', $menus)->delete();
        DB::table('menus')->whereIn('id', $menus)->delete();
        foreach (['pay_tax_monthly_histories', 'pay_run_reference_versions', 'pay_run_items', 'pay_run_employees', 'pay_runs'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropConstrainedForeignId('approver_user_id'));
    }
};
