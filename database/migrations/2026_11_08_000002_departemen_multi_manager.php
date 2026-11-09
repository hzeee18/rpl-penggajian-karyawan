<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Satu departemen bisa punya lebih dari satu manager (termasuk Manager HR). manager_id tetap = manager utama. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departemen_manager', function (Blueprint $t) {
            $t->id();
            $t->foreignId('departemen_id')->constrained('departemen')->cascadeOnDelete();
            $t->foreignId('karyawan_id')->constrained('karyawan')->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['departemen_id', 'karyawan_id']);
        });
        foreach (DB::table('departemen')->whereNotNull('manager_id')->get() as $d) {
            DB::table('departemen_manager')->insert(['departemen_id' => $d->id, 'karyawan_id' => $d->manager_id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('departemen_manager');
    }
};
