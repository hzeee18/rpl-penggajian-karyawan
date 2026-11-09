<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Aktifkan kontrak yang tanggal mulainya sudah tiba (jalankan via scheduler / cron).
Artisan::command('kontrak:aktifkan', function () {
    \App\Models\Kontrak::sinkronAktif();
    $this->info('Kontrak aktif sudah disinkronkan.');
})->purpose('Aktifkan kontrak yang sudah disetujui dan mulai berlaku');

\Illuminate\Support\Facades\Schedule::command('kontrak:aktifkan')->dailyAt('00:05');
