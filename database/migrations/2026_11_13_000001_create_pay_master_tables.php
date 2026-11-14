<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 (payroll baru): data master per perusahaan (docs/schema.sql §2, §8) dengan prefix pay_.
 * Tabel baru saja; tidak menyentuh data lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('action', 64);
            $t->string('entity_type', 100);
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->json('old_values')->nullable();
            $t->json('new_values')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index(['entity_type', 'entity_id'], 'idx_audit_entity');
            $t->index(['company_id', 'created_at'], 'idx_audit_company_date');
            $t->index(['user_id', 'created_at'], 'idx_audit_user');
        });

        Schema::create('pay_period_configs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->unique('uq_ppc_company')->constrained('companies');
            $t->unsignedTinyInteger('start_day');
            $t->unsignedTinyInteger('end_day');
            $t->boolean('start_in_previous_month')->default(false);
            $t->timestamps();
        });

        Schema::create('pay_holidays', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies');
            $t->date('holiday_date');
            $t->string('name', 191);
            $t->timestamps();
            $t->unique(['company_id', 'holiday_date'], 'uq_pay_holidays_company_date');
        });

        Schema::create('pay_positions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies');
            $t->string('code', 32);
            $t->string('name', 191);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['company_id', 'code'], 'uq_pay_positions_company_code');
        });

        Schema::create('pay_leave_types', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies');
            $t->string('code', 32);
            $t->string('name', 100);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['company_id', 'code'], 'uq_pay_leave_types_company_code');
        });

        Schema::create('pay_components', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies');
            $t->string('code', 64);
            $t->string('name', 191);
            $t->enum('type', ['earning', 'deduction']);
            $t->boolean('is_taxable')->default(false);
            $t->boolean('is_contractable')->default(true);
            $t->decimal('default_amount', 15, 2)->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['company_id', 'code'], 'uq_pay_components_company_code');
        });
    }

    public function down(): void
    {
        foreach (['pay_components', 'pay_leave_types', 'pay_positions', 'pay_holidays', 'pay_period_configs', 'audit_logs'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
