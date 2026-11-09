<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Nomor kontrak otomatis + rantai perpanjangan (kontrak baru menunjuk kontrak sebelumnya). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kontrak', function (Blueprint $t) {
            $t->string('nomor', 40)->nullable()->unique()->after('id');
            $t->foreignId('kontrak_sebelumnya_id')->nullable()->after('karyawan_id')->constrained('kontrak')->nullOnDelete();
        });

        \App\Models\Kontrak::isiNomorKosong();
    }

    public function down(): void
    {
        Schema::table('kontrak', function (Blueprint $t) {
            $t->dropConstrainedForeignId('kontrak_sebelumnya_id');
            $t->dropUnique(['nomor']);
            $t->dropColumn('nomor');
        });
    }
};
