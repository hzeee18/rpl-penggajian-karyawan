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
