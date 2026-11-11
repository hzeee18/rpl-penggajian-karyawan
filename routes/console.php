<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Aktifkan kontrak yang tanggal mulainya sudah tiba (jalankan via scheduler / cron).
Artisan::command('kontrak:aktifkan', function () {
    // multi-tenant: dijalankan per perusahaan dengan konteks eksplisit (semua perusahaan, termasuk yang
    // nonaktif, agar perilakunya sama seperti sebelum multi-tenant: semua kontrak diproses)
    foreach (\App\Domain\Company\Models\Company::orderBy('id')->pluck('id') as $companyId) {
        app(\App\Domain\Company\Tenancy\CurrentCompany::class)->runAs((int) $companyId, fn () => \App\Models\Kontrak::sinkronAktif());
    }
    $this->info('Kontrak aktif sudah disinkronkan.');
})->purpose('Aktifkan kontrak yang sudah disetujui dan mulai berlaku');

\Illuminate\Support\Facades\Schedule::command('kontrak:aktifkan')->dailyAt('00:05');

// Multi-tenant: buat akun Super Admin (platform, company_id NULL). Tidak bisa dibuat dari UI, disengaja.
Artisan::command('super-admin:buat {username} {--email=} {--password=}', function (string $username) {
    if (\Illuminate\Support\Facades\DB::table('users')->where('username', $username)->exists()) {
        $this->error("Username {$username} sudah dipakai.");

        return 1;
    }
    $roleId = (new \App\Domain\Company\Actions\PasangHakPlatform)->handle();
    $password = $this->option('password') ?: \Illuminate\Support\Str::password(16, symbols: false);
    \Illuminate\Support\Facades\DB::table('users')->insert([
        'company_id' => null, 'role_id' => $roleId, 'username' => $username, 'email' => $this->option('email'),
        'password' => \Illuminate\Support\Facades\Hash::make($password), 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->info("Super Admin {$username} dibuat.");
    if (! $this->option('password')) {
        $this->line("Password awal: {$password} (segera ganti setelah login)");
    }

    return 0;
})->purpose('Buat akun Super Admin (lintas perusahaan, mengelola perusahaan dan tabel TER nasional)');

// Payroll baru: beri hak akses bawaan menu payroll baru ke role satu perusahaan (HR, Admin, Manager, Karyawan).
Artisan::command('payroll:aktifkan {kode : kode perusahaan}', function (string $kode) {
    $id = \App\Domain\Company\Models\Company::where('code', $kode)->value('id');
    if ($id === null) {
        $this->error("Perusahaan {$kode} tidak ditemukan.");

        return 1;
    }
    (new \App\Domain\Payroll\Actions\PasangMenuPayroll)->berikanHak((int) $id);
    $this->info("Menu payroll baru aktif untuk perusahaan {$kode}.");

    return 0;
})->purpose('Aktifkan menu payroll baru (hak akses bawaan) untuk satu perusahaan');
