<?php

declare(strict_types=1);

use App\Domain\Payroll\Actions\PasangMenuPayroll;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 7 (payroll baru): slip gaji (docs/schema.sql payslips), prefix pay_.
 * Tambahan di luar schema: kolom `note` (alasan PDF belum terbit, mis. nomor rekening kosong untuk password).
 * Juga memasang menu Slip Gaji payroll baru (tanpa hak bawaan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_payslips', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies');
            $t->foreignId('run_employee_id')->unique('uq_pay_payslips_run_employee')->constrained('pay_run_employees');
            $t->foreignId('employee_id')->constrained('karyawan');
            $t->string('file_path')->nullable();
            $t->timestamp('generated_at')->nullable();
            $t->string('note')->nullable();
            $t->timestamps();
            $t->index(['employee_id', 'generated_at'], 'idx_pay_payslips_employee');
        });

        if (DB::table('menus')->exists()) {
            (new PasangMenuPayroll)->handle();
        }
    }

    public function down(): void
    {
        $menu = DB::table('menus')->where('url', 'pay-slip')->value('id');
        if ($menu !== null) {
            DB::table('privileges')->where('menu_id', $menu)->delete();
            DB::table('menus')->where('id', $menu)->delete();
        }
        Schema::dropIfExists('pay_payslips');
    }
};
