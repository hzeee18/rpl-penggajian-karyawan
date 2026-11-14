<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1a (multi-tenant): tabel tenant (docs/schema.sql §1) + satu perusahaan default
 * untuk menampung data lama (keputusan AUDIT.md §9 butir 3).
 */
return new class extends Migration
{
    public const DEFAULT_CODE = 'DEFAULT';

    public function up(): void
    {
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('code', 32)->unique('uq_companies_code');
            $t->string('name', 191);
            $t->text('address')->nullable();
            $t->string('logo_path')->nullable();
            $t->string('signer_name', 191)->nullable();
            $t->string('signer_title', 191)->nullable();
            $t->string('signature_path')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        DB::table('companies')->insert([
            'code' => self::DEFAULT_CODE,
            'name' => 'Perusahaan Default',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
