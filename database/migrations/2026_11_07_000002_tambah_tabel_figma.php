<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tambahan yang dibutuhkan layar di Figma. */
return new class extends Migration
{
    public function up(): void
    {
        // Dashboard Admin: Log Aktivitas Terbaru
        Schema::create('log_aktivitas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('aktivitas');
            $t->string('modul', 30);
            $t->timestamp('created_at')->nullable();
        });

        // Import Excel: riwayat file yang di-upload
        Schema::create('import_excel', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('jenis', 20);
            $t->string('nama_file');
            $t->string('status', 15);      // berhasil / error
            $t->integer('baris')->default(0);
            $t->integer('baris_error')->default(0);
            $t->text('catatan')->nullable();
            $t->timestamps();
        });

        // Komponen Gaji: kolom KODE (GP, UM, UT, LB, THR, ALF)
        Schema::table('payslip_component', function (Blueprint $t) {
            $t->string('kode', 5)->nullable()->after('id');
            // status kehadiran yang dibayar untuk komponen harian, mis. "hadir,sakit,izin,cuti"
            $t->string('dihitung_per', 50)->nullable()->after('dasar_bayar');
        });

        // Approval: siapa yang mengajukan (kolom "Diajukan oleh" di layar Approval)
        Schema::table('approval', function (Blueprint $t) {
            $t->foreignId('diajukan_oleh')->nullable()->after('approver_id')->constrained('users')->nullOnDelete();
        });

        // Master Payroll: satu baris per periode (tanggal mulai, selesai, hari kerja)
        Schema::table('master_payroll', function (Blueprint $t) {
            $t->string('periode', 7)->nullable()->unique()->after('id');
            $t->date('tgl_mulai')->nullable()->after('periode');
            $t->date('tgl_selesai')->nullable()->after('tgl_mulai');
            $t->foreignId('dibuat_oleh')->nullable()->after('pengali_jam_berikut')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('master_payroll', function (Blueprint $t) {
            $t->dropConstrainedForeignId('dibuat_oleh');
            $t->dropUnique(['periode']);
            $t->dropColumn(['periode', 'tgl_mulai', 'tgl_selesai']);
        });
        Schema::table('approval', fn (Blueprint $t) => $t->dropConstrainedForeignId('diajukan_oleh'));
        Schema::table('payslip_component', fn (Blueprint $t) => $t->dropColumn(['kode', 'dihitung_per']));
        Schema::dropIfExists('import_excel');
        Schema::dropIfExists('log_aktivitas');
    }
};
