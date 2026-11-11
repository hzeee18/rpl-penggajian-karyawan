<?php

use App\Http\Controllers;
use App\Http\Controllers\Admin;
use App\Http\Controllers\Master;
use Illuminate\Support\Facades\Route;

// ===== Umum (belum login) =====
Route::middleware('guest')->group(function () {
    Route::get('/login', [Controllers\AuthController::class, 'formLogin'])->name('login');
    Route::post('/login', [Controllers\AuthController::class, 'login'])->middleware('throttle:20,1,login');
    Route::get('/lupa-password', [Controllers\AuthController::class, 'formLupa'])->name('password.lupa');
    Route::post('/lupa-password', [Controllers\AuthController::class, 'lupa'])->middleware('throttle:5,1,lupa');
});

Route::get('/', fn () => redirect()->route('dashboard'));

Route::middleware(['auth', 'aktif'])->group(function () {
    Route::post('/logout', [Controllers\AuthController::class, 'logout'])->name('logout');
    Route::get('/dashboard', [Controllers\DashboardController::class, 'index'])->name('dashboard');
    Route::put('/ganti-password', [Controllers\AuthController::class, 'gantiPassword'])->name('password.ganti');

    // Notifikasi (semua role)
    Route::get('/cari', Controllers\CariController::class)->middleware('throttle:60,1')->name('cari');
    Route::get('/notifikasi', [Controllers\NotifikasiController::class, 'index'])->name('notifikasi.index');
    Route::get('/notifikasi/{notifikasi}', [Controllers\NotifikasiController::class, 'buka'])->name('notifikasi.buka');
    Route::post('/notifikasi/baca-semua', [Controllers\NotifikasiController::class, 'bacaSemua'])->name('notifikasi.bacaSemua');

    // ===== Admin: user, role, menu, hak akses =====
    Route::middleware('akses:users')->group(function () {
        Route::get('/users', [Admin\UserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [Admin\UserController::class, 'create'])->middleware('akses:users,create')->name('users.create');
        Route::post('/users', [Admin\UserController::class, 'store'])->middleware('akses:users,create')->name('users.store');
        Route::get('/users/{user}/edit', [Admin\UserController::class, 'edit'])->middleware('akses:users,update')->name('users.edit');
        Route::put('/users/{user}', [Admin\UserController::class, 'update'])->middleware('akses:users,update')->name('users.update');
        Route::post('/users/{user}/buka-kunci', [Admin\UserController::class, 'bukaKunci'])->middleware('akses:users,update')->name('users.bukaKunci');
        Route::post('/users/{user}/reset-password', [Admin\UserController::class, 'resetPassword'])->middleware('akses:users,update')->name('users.resetPassword');
        Route::delete('/users/{user}', [Admin\UserController::class, 'destroy'])->middleware('akses:users,delete')->name('users.destroy');
    });
    Route::middleware('akses:roles')->group(function () {
        Route::get('/roles', [Admin\RoleController::class, 'index'])->name('roles.index');
        Route::post('/roles', [Admin\RoleController::class, 'store'])->middleware('akses:roles,create')->name('roles.store');
        Route::get('/roles/{role}/hak-akses', [Admin\RoleController::class, 'hakAkses'])->name('roles.hakAkses');
        Route::put('/roles/{role}/hak-akses', [Admin\RoleController::class, 'simpanHakAkses'])->middleware('akses:roles,update')->name('roles.simpanHakAkses');
        Route::delete('/roles/{role}', [Admin\RoleController::class, 'destroy'])->middleware('akses:roles,delete')->name('roles.destroy');
    });
    Route::middleware('akses:menus')->group(function () {
        Route::get('/menus', [Admin\MenuController::class, 'index'])->name('menus.index');
        Route::post('/menus', [Admin\MenuController::class, 'store'])->middleware('akses:menus,create')->name('menus.store');
        Route::put('/menus/{menu}', [Admin\MenuController::class, 'update'])->middleware('akses:menus,update')->name('menus.update');
        Route::delete('/menus/{menu}', [Admin\MenuController::class, 'destroy'])->middleware('akses:menus,delete')->name('menus.destroy');
    });

    // ===== Platform (Super Admin): perusahaan =====
    Route::middleware(['super-admin', 'akses:perusahaan'])->prefix('perusahaan')->name('perusahaan.')->group(function () {
        Route::get('/', [Controllers\Platform\PerusahaanController::class, 'index'])->name('index');
        Route::post('/', [Controllers\Platform\PerusahaanController::class, 'store'])->middleware('akses:perusahaan,create')->name('store');
        Route::put('/{perusahaan}', [Controllers\Platform\PerusahaanController::class, 'update'])->middleware('akses:perusahaan,update')->name('update');
        Route::post('/keluar', [Controllers\Platform\PerusahaanController::class, 'keluar'])->name('keluar');
        Route::post('/{perusahaan}/pilih', [Controllers\Platform\PerusahaanController::class, 'pilih'])->name('pilih');
    });

    // ===== Master data (HR) =====
    crud('departemen', Master\DepartemenController::class);
    Route::post('/komponen/pratinjau', [Master\KomponenController::class, 'pratinjau'])->middleware('akses:komponen')->name('komponen.pratinjau');
    crud('komponen', Master\KomponenController::class);

    Route::middleware('akses:pajak')->prefix('pajak')->name('pajak.')->group(function () {
        Route::get('/', [Master\PajakController::class, 'index'])->name('index');
        Route::post('/golongan', [Master\PajakController::class, 'simpanGolongan'])->middleware('akses:pajak,create')->name('golongan.store');
        Route::put('/golongan/{golongan}', [Master\PajakController::class, 'ubahGolongan'])->middleware('akses:pajak,update')->name('golongan.update');
        Route::delete('/golongan/{golongan}', [Master\PajakController::class, 'hapusGolongan'])->middleware('akses:pajak,delete')->name('golongan.destroy');
        Route::post('/ptkp', [Master\PajakController::class, 'simpanPtkp'])->middleware('akses:pajak,create')->name('ptkp.store');
        Route::put('/ptkp/{pajak}', [Master\PajakController::class, 'ubahPtkp'])->middleware('akses:pajak,update')->name('ptkp.update');
        Route::post('/tarif', [Master\PajakController::class, 'simpanTarif'])->middleware('akses:pajak,create')->name('tarif.store');
        Route::put('/tarif/{tarif}', [Master\PajakController::class, 'ubahTarif'])->middleware('akses:pajak,update')->name('tarif.update');
        Route::delete('/tarif/{tarif}', [Master\PajakController::class, 'hapusTarif'])->middleware('akses:pajak,delete')->name('tarif.destroy');
    });

    Route::middleware('akses:master-payroll')->group(function () {
        Route::get('/master-payroll', [Master\MasterPayrollController::class, 'index'])->name('master-payroll.index');
        Route::post('/master-payroll', [Master\MasterPayrollController::class, 'store'])->middleware('akses:master-payroll,create')->name('master-payroll.store');
        Route::put('/master-payroll/{master}', [Master\MasterPayrollController::class, 'update'])->middleware('akses:master-payroll,update')->name('master-payroll.update');
        Route::delete('/master-payroll/{master}', [Master\MasterPayrollController::class, 'destroy'])->middleware('akses:master-payroll,delete')->name('master-payroll.destroy');
    });

    // ===== Karyawan & kontrak =====
    crud('karyawan', Controllers\KaryawanController::class, show: true);
    crud('kontrak', Controllers\KontrakController::class, show: true);

    // ===== Absensi, cuti, lembur =====
    Route::post('/absensi/harian', [Controllers\AttendanceController::class, 'simpanHarian'])->middleware('akses:absensi,update')->name('absensi.harian');
    crud('absensi', Controllers\AttendanceController::class, param: 'attendance');
    crud('cuti', Controllers\LeaveController::class, param: 'leave');
    crud('lembur', Controllers\OvertimeController::class, param: 'overtime');

    // ===== Import Excel =====
    Route::middleware('akses:import')->prefix('import')->name('import.')->group(function () {
        Route::get('/', [Controllers\ImportController::class, 'index'])->name('index');
        Route::get('/template/{jenis}', [Controllers\ImportController::class, 'template'])->name('template');
        Route::post('/upload', [Controllers\ImportController::class, 'upload'])->middleware('akses:import,create')->name('upload');
        Route::get('/preview', [Controllers\ImportController::class, 'preview'])->name('preview');
        Route::post('/simpan', [Controllers\ImportController::class, 'simpan'])->middleware('akses:import,create')->name('simpan');
    });

    // ===== Payroll =====
    Route::middleware('akses:payroll')->prefix('payroll')->name('payroll.')->group(function () {
        Route::get('/', [Controllers\PayrollController::class, 'index'])->name('index');
        Route::get('/rekap', [Controllers\PayrollController::class, 'rekap'])->name('rekap');
        Route::get('/riwayat', [Controllers\PayrollController::class, 'riwayat'])->name('riwayat');
        Route::post('/generate', [Controllers\PayrollController::class, 'generate'])->middleware('akses:payroll,create')->name('generate');
        Route::get('/{payroll}', [Controllers\PayrollController::class, 'show'])->name('show');
        Route::get('/{payroll}/karyawan/{karyawan}', [Controllers\PayrollController::class, 'detailKaryawan'])->name('detailKaryawan');
        Route::post('/{payroll}/ajukan', [Controllers\PayrollController::class, 'ajukan'])->middleware('akses:payroll,update')->name('ajukan');
        Route::delete('/{payroll}', [Controllers\PayrollController::class, 'destroy'])->middleware('akses:payroll,delete')->name('destroy');
    });

    Route::middleware('akses:laporan')->prefix('laporan')->name('laporan.')->group(function () {
        Route::get('/', [Controllers\LaporanController::class, 'index'])->name('index');
        Route::get('/excel', [Controllers\LaporanController::class, 'excel'])->name('excel');
        Route::get('/pdf', [Controllers\LaporanController::class, 'pdf'])->name('pdf');
        Route::get('/{payroll}', [Controllers\LaporanController::class, 'show'])->name('show');
    });

    // ===== Approval (Manager) =====
    Route::middleware('akses:approval')->prefix('approval')->name('approval.')->group(function () {
        Route::get('/', [Controllers\ApprovalController::class, 'index'])->name('index');
        Route::get('/{approval}', [Controllers\ApprovalController::class, 'show'])->name('show');
        Route::post('/{approval}/setujui', [Controllers\ApprovalController::class, 'setujui'])->middleware('akses:approval,approve')->name('setujui');
        Route::post('/{approval}/tolak', [Controllers\ApprovalController::class, 'tolak'])->middleware('akses:approval,approve')->name('tolak');
    });

    // ===== Slip gaji & profil =====
    Route::middleware('akses:slip')->prefix('slip')->name('slip.')->group(function () {
        Route::get('/', [Controllers\PayslipController::class, 'index'])->name('index');
        Route::get('/{payslip}', [Controllers\PayslipController::class, 'show'])->name('show');
        Route::get('/{payslip}/unduh', [Controllers\PayslipController::class, 'download'])->name('download');
    });
    Route::get('/profil', [Controllers\ProfilController::class, 'index'])->middleware('akses:profil')->name('profil.index');
    Route::get('/kontrak-pdf/{kontrak}', [Controllers\ProfilController::class, 'kontrakPdf'])->name('kontrak.pdf');

    // ===== Payroll baru (modul berdampingan dengan payroll lama) =====
    require __DIR__.'/payroll-baru.php';
});
