<?php

/*
 * Route modul payroll baru. Dimuat dari routes/web.php di dalam grup ['auth', 'aktif'].
 * Hak akses: middleware akses:{url menu},{aksi} membaca tabel privileges (tidak di-hardcode).
 */

use App\Http\Controllers\PayrollBaru;
use Illuminate\Support\Facades\Route;

Route::prefix('pay')->name('pay.')->group(function () {
    $master = [
        'jabatan' => PayrollBaru\JabatanController::class,
        'libur' => PayrollBaru\LiburController::class,
        'tipe-leave' => PayrollBaru\TipeLeaveController::class,
        'komponen' => PayrollBaru\KomponenController::class,
    ];
    foreach ($master as $nama => $controller) {
        Route::middleware("akses:pay-{$nama}")->prefix($nama)->name("{$nama}.")->group(function () use ($nama, $controller) {
            Route::get('/', [$controller, 'index'])->name('index');
            Route::post('/', [$controller, 'store'])->middleware("akses:pay-{$nama},create")->name('store');
            Route::put('/{id}', [$controller, 'update'])->whereNumber('id')->middleware("akses:pay-{$nama},update")->name('update');
            Route::delete('/{id}', [$controller, 'destroy'])->whereNumber('id')->middleware("akses:pay-{$nama},delete")->name('destroy');
        });
    }

    Route::middleware('akses:pay-cutoff')->prefix('cutoff')->name('cutoff.')->group(function () {
        Route::get('/', [PayrollBaru\CutoffController::class, 'index'])->name('index');
        Route::put('/', [PayrollBaru\CutoffController::class, 'update'])->middleware('akses:pay-cutoff,update')->name('update');
    });

    Route::middleware('akses:pay-kontrak')->prefix('kontrak')->name('kontrak.')->group(function () {
        Route::get('/', [PayrollBaru\KontrakPayrollController::class, 'index'])->name('index');
        Route::post('/', [PayrollBaru\KontrakPayrollController::class, 'store'])->middleware('akses:pay-kontrak,create')->name('store');
        Route::post('/{id}/perbarui', [PayrollBaru\KontrakPayrollController::class, 'perbarui'])->whereNumber('id')->middleware('akses:pay-kontrak,create')->name('perbarui');
        Route::post('/{id}/nonaktifkan', [PayrollBaru\KontrakPayrollController::class, 'nonaktifkan'])->whereNumber('id')->middleware('akses:pay-kontrak,delete')->name('nonaktifkan');
    });

    // Fase 4: absensi / leave / lembur (manual) + import CSV
    foreach (['absensi', 'leave', 'lembur'] as $jenis) {
        Route::middleware("akses:pay-{$jenis}")->prefix($jenis)->name("{$jenis}.")->group(function () use ($jenis) {
            Route::get('/', [PayrollBaru\KehadiranPayrollController::class, 'index'])->defaults('jenis', $jenis)->name('index');
            Route::post('/', [PayrollBaru\KehadiranPayrollController::class, 'store'])->defaults('jenis', $jenis)->middleware("akses:pay-{$jenis},create")->name('store');
            Route::put('/{id}', [PayrollBaru\KehadiranPayrollController::class, 'update'])->defaults('jenis', $jenis)->whereNumber('id')->middleware("akses:pay-{$jenis},update")->name('update');
            Route::delete('/{id}', [PayrollBaru\KehadiranPayrollController::class, 'destroy'])->defaults('jenis', $jenis)->whereNumber('id')->middleware("akses:pay-{$jenis},delete")->name('destroy');
        });
    }

    Route::middleware('akses:pay-import')->prefix('import')->name('import.')->group(function () {
        Route::get('/', [PayrollBaru\ImportPayrollController::class, 'index'])->name('index');
        Route::get('/template/{jenis}', [PayrollBaru\ImportPayrollController::class, 'template'])->whereIn('jenis', ['absensi', 'leave', 'lembur'])->name('template');
        Route::post('/', [PayrollBaru\ImportPayrollController::class, 'upload'])->middleware('akses:pay-import,create')->name('upload');
        Route::get('/{id}', [PayrollBaru\ImportPayrollController::class, 'show'])->whereNumber('id')->name('show');
        Route::put('/{id}/baris/{baris}', [PayrollBaru\ImportPayrollController::class, 'ubahBaris'])->whereNumber(['id', 'baris'])->middleware('akses:pay-import,update')->name('baris.update');
        Route::delete('/{id}/baris/{baris}', [PayrollBaru\ImportPayrollController::class, 'hapusBaris'])->whereNumber(['id', 'baris'])->middleware('akses:pay-import,delete')->name('baris.destroy');
        Route::post('/{id}/simpan', [PayrollBaru\ImportPayrollController::class, 'simpan'])->whereNumber('id')->middleware('akses:pay-import,create')->name('simpan');
        Route::post('/{id}/batal', [PayrollBaru\ImportPayrollController::class, 'batal'])->whereNumber('id')->middleware('akses:pay-import,delete')->name('batal');
    });

    Route::middleware('akses:pay-karyawan')->prefix('karyawan')->name('karyawan.')->group(function () {
        Route::get('/', [PayrollBaru\KaryawanPayrollController::class, 'index'])->name('index');
        Route::put('/{id}', [PayrollBaru\KaryawanPayrollController::class, 'update'])->whereNumber('id')->middleware('akses:pay-karyawan,update')->name('update');
    });
});
