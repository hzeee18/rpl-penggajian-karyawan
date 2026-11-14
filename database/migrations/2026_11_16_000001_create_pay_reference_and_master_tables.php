<?php

declare(strict_types=1);

use App\Domain\Payroll\Actions\PasangMenuPayroll;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 5 (payroll baru): tabel referensi berversi (docs/schema.sql §5) dan master payroll berversi
 * (variabel, parameter, item komponen; §6), prefix pay_.
 * Tabel referensi dengan company_id NULL = nasional (mis. TER), hanya diubah Super Admin.
 * Juga memasang menu Tabel Referensi dan Master Payroll (tanpa hak bawaan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pay_reference_tables', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies');
            $t->unsignedBigInteger('company_key')->storedAs('coalesce(company_id, 0)');
            $t->string('code', 64);
            $t->string('name', 191);
            $t->string('description')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['company_key', 'code'], 'uq_pay_reftables_company_code');
        });

        Schema::create('pay_reference_table_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies');
            $t->foreignId('reference_table_id')->constrained('pay_reference_tables');
            $t->unsignedInteger('version_no');
            $t->enum('status', ['draft', 'published'])->default('draft');
            $t->date('effective_from')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamp('locked_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique(['reference_table_id', 'version_no'], 'uq_pay_rtv_table_version');
        });

        Schema::create('pay_reference_table_rows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies');
            $t->foreignId('version_id')->constrained('pay_reference_table_versions')->cascadeOnDelete();
            $t->string('key_value', 64)->nullable();
            $t->decimal('range_from', 18, 2)->nullable();
            $t->decimal('range_to', 18, 2)->nullable();
            $t->decimal('result_number', 18, 6)->nullable();
            $t->string('result_text', 64)->nullable();
            $t->integer('sort_order')->default(0);
            $t->index(['version_id', 'key_value', 'range_from'], 'idx_pay_rtr_lookup');
        });

        Schema::create('pay_masters', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies');
            $t->string('code', 64);
            $t->string('name', 191);
            $t->string('description')->nullable();
            $t->unsignedBigInteger('current_version_id')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['company_id', 'code'], 'uq_pay_masters_company_code');
        });

        Schema::create('pay_master_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies');
            $t->foreignId('master_id')->constrained('pay_masters');
            $t->unsignedInteger('version_no');
            $t->enum('status', ['draft', 'published'])->default('draft');
            $t->string('note')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamp('locked_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique(['master_id', 'version_no'], 'uq_pay_mv_master_version');
        });

        Schema::table('pay_masters', fn (Blueprint $t) => $t->foreign('current_version_id')->references('id')->on('pay_master_versions'));

        Schema::create('pay_variables', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies');
            $t->foreignId('master_version_id')->constrained('pay_master_versions')->cascadeOnDelete();
            $t->string('code', 64);
            $t->string('name', 191);
            $t->enum('kind', ['base', 'derived']);
            $t->enum('source', ['attendances', 'leaves', 'overtimes', 'employees', 'contracts', 'holidays'])->nullable();
            $t->text('filter_expression')->nullable();
            $t->string('value_expression', 500)->nullable();
            $t->enum('aggregate', ['count', 'sum', 'avg', 'min', 'max', 'count_distinct_date'])->nullable();
            $t->text('formula')->nullable();
            $t->integer('sort_order')->default(0);
            $t->timestamps();
            $t->unique(['master_version_id', 'code'], 'uq_pay_variables_version_code');
            $t->index(['master_version_id', 'sort_order'], 'idx_pay_variables_order');
        });

        Schema::create('pay_params', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies');
            $t->foreignId('master_version_id')->constrained('pay_master_versions')->cascadeOnDelete();
            $t->string('code', 64);
            $t->string('name', 191);
            $t->decimal('value', 18, 4);
            $t->timestamps();
            $t->unique(['master_version_id', 'code'], 'uq_pay_params_version_code');
        });

        Schema::create('pay_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies');
            $t->foreignId('master_version_id')->constrained('pay_master_versions')->cascadeOnDelete();
            $t->foreignId('component_id')->constrained('pay_components');
            $t->string('code', 64);
            $t->string('name', 191);
            $t->enum('type', ['earning', 'deduction']);
            $t->boolean('is_taxable')->default(false);
            $t->enum('calc_type', ['contract', 'formula']);
            $t->text('formula')->nullable();
            $t->enum('item_role', ['normal', 'pph21'])->default('normal');
            $t->integer('sort_order')->default(0);
            $t->timestamps();
            $t->unique(['master_version_id', 'code'], 'uq_pay_items_version_code');
            $t->unique(['master_version_id', 'component_id'], 'uq_pay_items_version_component');
            $t->index(['master_version_id', 'sort_order'], 'idx_pay_items_order');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE pay_reference_table_rows ADD CONSTRAINT ck_pay_rtr_range CHECK (range_from IS NULL OR range_to IS NULL OR range_to >= range_from)');
            DB::statement("ALTER TABLE pay_variables ADD CONSTRAINT ck_pay_variables_kind CHECK ((kind = 'base' AND source IS NOT NULL AND aggregate IS NOT NULL) OR (kind = 'derived' AND formula IS NOT NULL))");
            DB::statement("ALTER TABLE pay_items ADD CONSTRAINT ck_pay_items_formula CHECK (calc_type = 'contract' OR formula IS NOT NULL)");
        }

        if (DB::table('menus')->exists()) {
            (new PasangMenuPayroll)->handle();
        }
    }

    public function down(): void
    {
        $menus = DB::table('menus')->whereIn('url', ['pay-referensi', 'pay-master'])->pluck('id');
        DB::table('privileges')->whereIn('menu_id', $menus)->delete();
        DB::table('menus')->whereIn('id', $menus)->delete();

        Schema::table('pay_masters', fn (Blueprint $t) => $t->dropForeign(['current_version_id']));
        foreach (['pay_items', 'pay_params', 'pay_variables', 'pay_master_versions', 'pay_masters',
            'pay_reference_table_rows', 'pay_reference_table_versions', 'pay_reference_tables'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
