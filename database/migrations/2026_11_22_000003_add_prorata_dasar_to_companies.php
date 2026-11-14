<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keputusan pemilik (AUDIT.md §51): kontrak dipilih dari tanggal berlakunya dan komponen kontrak
 * dibayar prorata bila kontrak/masa kerja tidak penuh satu periode (karyawan baru, resign, ganti kontrak).
 * Dasar prorata = setelan perusahaan: kalender (hari kalender), kerja (hari kerja), tidak (tanpa prorata).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->string('prorata_dasar', 10)->default('kalender')->after('slip_pakai_password');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->dropColumn('prorata_dasar');
        });
    }
};
