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
    // multi-tenant: menu bersifat global, hanya Super Admin (keputusan AUDIT.md §26)
    Route::middleware(['super-admin', 'akses:menus'])->group(function () {
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
        Route::delete('/{perusahaan}', [Controllers\Platform\PerusahaanController::class, 'destroy'])->middleware('akses:perusahaan,delete')->name('destroy');
        Route::post('/{perusahaan}/status', [Controllers\Platform\PerusahaanController::class, 'status'])->middleware('akses:perusahaan,update')->name('status');
        Route::post('/keluar', [Controllers\Platform\PerusahaanController::class, 'keluar'])->name('keluar');
        Route::post('/{perusahaan}/pilih', [Controllers\Platform\PerusahaanController::class, 'pilih'])->name('pilih');
        Route::post('/{perusahaan}/admin', [Controllers\Platform\PerusahaanController::class, 'tambahAdmin'])->middleware('akses:perusahaan,update')->name('admin.store');
    });

    // ===== Master data (HR) =====
    Route::middleware('perlu-perusahaan')->group(fn () => crud('departemen', Master\DepartemenController::class));
    // ===== Karyawan & kontrak =====
    crud('karyawan', Controllers\KaryawanController::class, show: true);

    // ===== Absensi (riwayat kehadiran milik karyawan sendiri; data dikelola di modul payroll) =====
    Route::get('/absensi', [Controllers\AttendanceController::class, 'index'])->middleware('akses:absensi')->name('absensi.index');

    // ===== Profil =====
    Route::get('/profil', [Controllers\ProfilController::class, 'index'])->middleware('akses:profil')->name('profil.index');

    // ===== Modul payroll =====
    require __DIR__.'/payroll.php';
});
