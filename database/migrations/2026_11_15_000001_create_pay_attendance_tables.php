<?php

declare(strict_types=1);

use App\Domain\Payroll\Actions\PasangMenuPayroll;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 (payroll baru): absensi, leave, lembur + import CSV dengan preview (docs/schema.sql §4), prefix pay_.
 * Tabel terpisah dari attendance/leave/overtime lama (keputusan AUDIT.md §6.2 / §9 butir 1).
 * Juga memasang menu Absensi/Leave/Lembur/Import payroll (tanpa hak bawaan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_import_batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies');
            $t->enum('type', ['employees', 'attendances', 'leaves', 'overtimes']);
            $t->string('file_name');
            $t->unsignedInteger('total_rows')->default(0);
            $t->unsignedInteger('valid_rows')->default(0);
            $t->enum('status', ['previewing', 'committed', 'cancelled'])->default('previewing');
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamp('committed_at')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'type', 'status'], 'idx_pay_import_batches_company');
        });

        Schema::create('pay_import_batch_rows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies');
            $t->foreignId('batch_id')->constrained('pay_import_batches')->cascadeOnDelete();
            $t->unsignedInteger('row_no');
            $t->json('payload');
            $t->boolean('is_valid')->default(false);
            $t->string('error_message', 500)->nullable();
            $t->index(['batch_id', 'is_valid'], 'idx_pay_ibr_batch');
        });

        Schema::create('pay_attendances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies');
            $t->foreignId('employee_id')->constrained('karyawan');
            $t->date('attendance_date');
            $t->time('check_in')->nullable();
            $t->time('check_out')->nullable();
            $t->decimal('late_hours', 5, 2)->default(0);
            $t->foreignId('import_batch_id')->nullable()->constrained('pay_import_batches')->nullOnDelete();
            $t->timestamps();
            $t->unique(['employee_id', 'attendance_date'], 'uq_pay_attendances_employee_date');
            $t->index(['company_id', 'attendance_date'], 'idx_pay_attendances_company_date');
        });

        Schema::create('pay_leaves', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies');
            $t->foreignId('employee_id')->constrained('karyawan');
            $t->foreignId('leave_type_id')->constrained('pay_leave_types');
            $t->date('leave_date');
            $t->string('note', 255)->nullable();
            $t->foreignId('import_batch_id')->nullable()->constrained('pay_import_batches')->nullOnDelete();
            $t->timestamps();
            $t->unique(['employee_id', 'leave_date'], 'uq_pay_leaves_employee_date');
            $t->index(['company_id', 'leave_date'], 'idx_pay_leaves_company_date');
        });

        Schema::create('pay_overtimes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies');
            $t->foreignId('employee_id')->constrained('karyawan');
            $t->date('overtime_date');
            $t->decimal('duration_hours', 5, 2);
            $t->string('note', 255)->nullable();
            $t->foreignId('import_batch_id')->nullable()->constrained('pay_import_batches')->nullOnDelete();
            $t->timestamps();
            $t->index(['employee_id', 'overtime_date'], 'idx_pay_overtimes_employee_date');
            $t->index(['company_id', 'overtime_date'], 'idx_pay_overtimes_company_date');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE pay_overtimes ADD CONSTRAINT ck_pay_ot_duration CHECK (duration_hours > 0)');
        }

        if (DB::table('menus')->exists()) {
            (new PasangMenuPayroll)->handle();
        }
    }

    public function down(): void
    {
        $menus = DB::table('menus')->whereIn('url', ['pay-absensi', 'pay-leave', 'pay-lembur', 'pay-import'])->pluck('id');
        DB::table('privileges')->whereIn('menu_id', $menus)->delete();
        DB::table('menus')->whereIn('id', $menus)->delete();
        foreach (['pay_overtimes', 'pay_leaves', 'pay_attendances', 'pay_import_batch_rows', 'pay_import_batches'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
