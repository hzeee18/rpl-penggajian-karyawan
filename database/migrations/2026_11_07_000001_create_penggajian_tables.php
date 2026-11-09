<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Struktur tabel mengikuti ERD (uml/05-erd-database.mmd).
 * Kolom tambahan di luar ERD diberi komentar "(SRS)".
 */
return new class extends Migration
{
    public function up(): void
    {
        // ===== Hak akses =====
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('nama', 50)->unique();
            $t->timestamps();
        });

        Schema::create('menus', function (Blueprint $t) {
            $t->id();
            $t->foreignId('parent_id')->nullable()->constrained('menus')->nullOnDelete();
            $t->string('nama', 100);
            $t->string('url', 100)->nullable();     // kunci akses, mis. "karyawan"
            $t->string('icon', 50)->nullable();     // (UI) ikon sidebar
            $t->integer('urutan')->default(0);      // (UI) urutan sidebar
            $t->timestamps();
        });

        Schema::create('privileges', function (Blueprint $t) {
            $t->id();
            $t->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $t->foreignId('menu_id')->constrained('menus')->cascadeOnDelete();
            $t->boolean('can_view')->default(false);
            $t->boolean('can_create')->default(false);
            $t->boolean('can_update')->default(false);
            $t->boolean('can_delete')->default(false);
            $t->boolean('can_approve')->default(false);
            $t->timestamps();
            $t->unique(['role_id', 'menu_id']);
        });

        // ===== Pajak (PPh 21 TER) =====
        Schema::create('golongan_ter', function (Blueprint $t) {
            $t->id();
            $t->string('kode', 5)->unique();   // A, B, C
            $t->string('nama', 100);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('pajak', function (Blueprint $t) {
            $t->id();
            $t->string('status_ptkp', 10)->unique();   // TK/0, K/1, ...
            $t->decimal('nilai_ptkp', 15, 2);
            $t->foreignId('golongan_ter_id')->constrained('golongan_ter');
            $t->timestamps();
        });

        Schema::create('tarif_ter', function (Blueprint $t) {
            $t->id();
            $t->foreignId('golongan_ter_id')->constrained('golongan_ter')->cascadeOnDelete();
            $t->decimal('batas_bawah', 15, 2);
            $t->decimal('batas_atas', 15, 2)->nullable(); // null = tak terbatas
            $t->decimal('persen', 5, 2);
            $t->integer('lapisan');
            $t->date('berlaku_mulai');
            $t->date('berlaku_sampai')->nullable();
            $t->string('dasar_hukum', 100)->nullable();
            $t->timestamps();
        });

        // ===== Organisasi & karyawan =====
        Schema::create('departemen', function (Blueprint $t) {
            $t->id();
            $t->string('kode', 10)->unique();
            $t->string('nama', 100);
            $t->unsignedBigInteger('manager_id')->nullable(); // FK ke karyawan (ditambah di bawah)
            $t->timestamps();
        });

        Schema::create('karyawan', function (Blueprint $t) {
            $t->id();
            $t->foreignId('departemen_id')->nullable()->constrained('departemen')->nullOnDelete();
            $t->foreignId('pajak_id')->nullable()->constrained('pajak')->nullOnDelete();
            $t->string('nik', 20)->unique();
            $t->string('nama', 100);
            $t->string('jabatan', 100)->nullable();
            $t->date('tgl_masuk')->nullable();
            $t->string('no_rekening', 30)->nullable();
            // (SRS) data pribadi tambahan
            $t->string('jenis_kelamin', 1)->nullable();  // L / P
            $t->string('email', 100)->nullable();
            $t->string('telepon', 20)->nullable();
            $t->text('alamat')->nullable();
            $t->string('nama_bank', 50)->nullable();
            $t->string('status_kepegawaian', 20)->default('kontrak'); // tetap / kontrak / harian
            $t->integer('sisa_cuti')->default(12);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::table('departemen', function (Blueprint $t) {
            $t->foreign('manager_id')->references('id')->on('karyawan')->nullOnDelete();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->foreignId('role_id')->constrained('roles');
            $t->foreignId('karyawan_id')->nullable()->unique()->constrained('karyawan')->nullOnDelete();
            $t->string('username', 50)->unique();
            $t->string('password');
            $t->string('email', 100)->nullable();
            $t->boolean('is_active')->default(true);
            $t->integer('gagal_login')->default(0);
            $t->boolean('is_locked')->default(false);
            $t->dateTime('last_login')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });

        // ===== Komponen gaji & kontrak =====
        Schema::create('payslip_component', function (Blueprint $t) {
            $t->id();
            $t->string('nama', 100);
            $t->string('jenis', 10);          // penambah / pengurang
            $t->string('tipe_nilai', 10);     // nominal / persen (dari gaji pokok)
            $t->decimal('nilai_default', 15, 2)->default(0);
            $t->string('dasar_bayar', 20);    // gaji_pokok, bulanan, hadir, lembur, alfa, pajak
            $t->string('status_berlaku', 10)->default('aktif'); // aktif / nonaktif
            $t->boolean('kena_pajak')->default(false);
            $t->timestamps();
        });

        Schema::create('kontrak', function (Blueprint $t) {
            $t->id();
            $t->foreignId('karyawan_id')->constrained('karyawan')->cascadeOnDelete();
            $t->string('jenis', 10);          // PKWT / PKWTT
            $t->date('tgl_mulai');
            $t->date('tgl_akhir')->nullable();
            $t->decimal('gaji_pokok', 15, 2);
            $t->string('status_approval', 15)->default('draft'); // draft, menunggu, disetujui, ditolak
            $t->boolean('is_active')->default(false);
            $t->timestamps();
        });

        Schema::create('kontrak_komponen', function (Blueprint $t) {
            $t->id();
            $t->foreignId('kontrak_id')->constrained('kontrak')->cascadeOnDelete();
            $t->foreignId('payslip_component_id')->constrained('payslip_component');
            $t->string('tipe_nilai', 10);
            $t->decimal('nilai', 15, 2)->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        // ===== Kehadiran =====
        Schema::create('attendance', function (Blueprint $t) {
            $t->id();
            $t->foreignId('karyawan_id')->constrained('karyawan')->cascadeOnDelete();
            $t->date('tanggal');
            $t->time('jam_masuk')->nullable();
            $t->time('jam_keluar')->nullable();
            $t->string('status', 15);        // hadir / terlambat / alfa
            $t->timestamps();
            $t->unique(['karyawan_id', 'tanggal']);
        });

        Schema::create('leave', function (Blueprint $t) {
            $t->id();
            $t->foreignId('karyawan_id')->constrained('karyawan')->cascadeOnDelete();
            $t->string('jenis', 10);         // cuti / izin / sakit
            $t->date('tgl_mulai');
            $t->date('tgl_selesai');
            $t->string('keterangan')->nullable();
            $t->string('sumber_input', 10)->default('manual'); // manual / import
            $t->timestamps();
        });

        Schema::create('overtime', function (Blueprint $t) {
            $t->id();
            $t->foreignId('karyawan_id')->constrained('karyawan')->cascadeOnDelete();
            $t->date('tanggal');
            $t->time('jam_mulai');
            $t->time('jam_selesai');
            $t->decimal('durasi', 5, 2);     // jam
            $t->string('keterangan')->nullable();
            $t->foreignId('diinput_oleh')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        // ===== Notifikasi & approval =====
        Schema::create('notifikasi', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('judul', 150);
            $t->text('pesan');
            $t->string('jenis_ref', 30)->nullable();
            $t->unsignedBigInteger('ref_id')->nullable();
            $t->boolean('is_read')->default(false);
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('approval_mapping', function (Blueprint $t) {
            $t->id();
            $t->string('jenis_ref', 30);     // kontrak / payroll
            $t->integer('level');
            $t->string('tipe_approver', 30); // manager_divisi / manager_hr
            $t->timestamps();
        });

        Schema::create('approval', function (Blueprint $t) {
            $t->id();
            $t->foreignId('approval_mapping_id')->nullable()->constrained('approval_mapping')->nullOnDelete();
            $t->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('jenis_ref', 30);
            $t->unsignedBigInteger('ref_id');
            $t->integer('level')->default(1);
            $t->string('status', 15)->default('menunggu'); // menunggu / disetujui / ditolak
            $t->text('catatan')->nullable();
            $t->date('tgl_approval')->nullable();
            $t->timestamps();
        });

        // ===== Payroll =====
        Schema::create('master_payroll', function (Blueprint $t) {
            $t->id();
            $t->integer('tgl_cutoff')->default(25);     // 0 = akhir bulan
            $t->integer('hari_kerja')->default(22);
            $t->integer('pembagi_lembur')->default(173);
            $t->decimal('pengali_jam1', 4, 2)->default(1.5);
            $t->decimal('pengali_jam_berikut', 4, 2)->default(2);
            $t->timestamps();
        });

        Schema::create('payroll', function (Blueprint $t) {
            $t->id();
            $t->foreignId('master_payroll_id')->nullable()->constrained('master_payroll')->nullOnDelete();
            $t->string('periode', 7)->unique(); // YYYY-MM
            $t->date('tgl_generate')->nullable();
            $t->string('status', 15)->default('draft'); // draft / menunggu / final / ditolak
            $t->timestamps();
        });

        Schema::create('payroll_detail', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payroll_id')->constrained('payroll')->cascadeOnDelete();
            $t->foreignId('karyawan_id')->constrained('karyawan')->cascadeOnDelete();
            $t->foreignId('payslip_component_id')->constrained('payslip_component');
            $t->decimal('jumlah', 10, 2)->default(1);   // hari / jam / 1
            $t->decimal('nominal', 15, 2)->default(0);
            $t->timestamps();
        });

        Schema::create('payslip', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payroll_id')->constrained('payroll')->cascadeOnDelete();
            $t->foreignId('karyawan_id')->constrained('karyawan')->cascadeOnDelete();
            $t->decimal('total_bruto', 15, 2);
            $t->decimal('total_potongan', 15, 2);
            $t->decimal('thp', 15, 2);
            $t->string('file_pdf')->nullable();
            $t->timestamps();
            $t->unique(['payroll_id', 'karyawan_id']);
        });
    }

    public function down(): void
    {
        foreach ([
            'payslip', 'payroll_detail', 'payroll', 'master_payroll', 'approval', 'approval_mapping',
            'notifikasi', 'overtime', 'leave', 'attendance', 'kontrak_komponen', 'kontrak',
            'payslip_component', 'users',
        ] as $tabel) {
            Schema::dropIfExists($tabel);
        }
        Schema::table('departemen', fn (Blueprint $t) => $t->dropForeign(['manager_id']));
        foreach (['karyawan', 'departemen', 'tarif_ter', 'pajak', 'golongan_ter', 'privileges', 'menus', 'roles'] as $tabel) {
            Schema::dropIfExists($tabel);
        }
    }
};
