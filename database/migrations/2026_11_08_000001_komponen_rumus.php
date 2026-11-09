<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Komponen gaji pakai rumus (mis. "GP * 15% / HK * HADIR"). Lembur jadi komponen biasa dengan rumus. */
return new class extends Migration
{
    public const BAWAAN = [
        'UM' => 'GP * 15% / HK * (HADIR + SAKIT + IZIN + CUTI)',
        'UT' => 'GP * 15% / HK * HADIR',
        'LB' => 'GP / 173 * (JAM_LEMBUR_1 * 1.5 + JAM_LEMBUR_LANJUT * 2)',
        'THR' => 'IF(MASA_KERJA >= 1, GP * MIN(MASA_KERJA, 12) / 12, 0)',
        'ALF' => 'GP / HK * ALFA',
    ];

    public function up(): void
    {
        Schema::table('payslip_component', function (Blueprint $t) {
            $t->string('rumus', 255)->nullable()->after('dasar_bayar');
        });
        foreach (self::BAWAAN as $kode => $rumus) {
            DB::table('payslip_component')->where('kode', $kode)->update(['rumus' => $rumus]);
        }
    }

    public function down(): void
    {
        Schema::table('payslip_component', fn (Blueprint $t) => $t->dropColumn('rumus'));
    }
};
