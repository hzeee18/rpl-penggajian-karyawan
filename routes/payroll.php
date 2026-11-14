<?php

/*
 * Route modul payroll baru. Dimuat dari routes/web.php di dalam grup ['auth', 'aktif'].
 * Hak akses: middleware akses:{url menu},{aksi} membaca tabel privileges (tidak di-hardcode).
 */

use App\Http\Controllers\Payroll;
use Illuminate\Support\Facades\Route;

Route::prefix('pay')->name('pay.')->group(function () {
    $master = [
        'jabatan' => Payroll\JabatanController::class,
        'libur' => Payroll\LiburController::class,
        'tipe-leave' => Payroll\TipeLeaveController::class,
        'komponen' => Payroll\KomponenController::class,
    ];
    foreach ($master as $nama => $controller) {
        // jabatan & tipe leave dikelola Super Admin (perlu perusahaan terpilih), AUDIT.md §34
        Route::middleware(['perlu-perusahaan', "akses:pay-{$nama}"])->prefix($nama)->name("{$nama}.")->group(function () use ($nama, $controller) {
            if ($nama === 'libur') { // Kalender Payroll: tab Periode + Hari Libur + Pola Hari Kerja (AUDIT.md §53, §55)
                Route::get('/', [Payroll\KalenderController::class, 'index'])->name('index');
                Route::post('/periode', [Payroll\KalenderController::class, 'store'])->middleware('akses:pay-libur,create')->name('periode.store');
                Route::put('/periode/{id}', [Payroll\KalenderController::class, 'update'])->whereNumber('id')->middleware('akses:pay-libur,update')->name('periode.update');
                Route::delete('/periode/{id}', [Payroll\KalenderController::class, 'destroy'])->whereNumber('id')->middleware('akses:pay-libur,delete')->name('periode.destroy');
                Route::post('/pola', [Payroll\KalenderController::class, 'simpanPola'])->middleware('akses:pay-libur,create')->name('pola.store');
                Route::put('/pola/{id}', [Payroll\KalenderController::class, 'simpanPola'])->whereNumber('id')->middleware('akses:pay-libur,update')->name('pola.update');
                Route::delete('/pola/{id}', [Payroll\KalenderController::class, 'hapusPola'])->whereNumber('id')->middleware('akses:pay-libur,delete')->name('pola.destroy');
                Route::put('/pola-pasang', [Payroll\KalenderController::class, 'pasangPola'])->middleware('akses:pay-libur,update')->name('pola.pasang');
            } else {
                Route::get('/', [$controller, 'index'])->name('index');
            }
            Route::post('/', [$controller, 'store'])->middleware("akses:pay-{$nama},create")->name('store');
            Route::put('/{id}', [$controller, 'update'])->whereNumber('id')->middleware("akses:pay-{$nama},update")->name('update');
            Route::delete('/{id}', [$controller, 'destroy'])->whereNumber('id')->middleware("akses:pay-{$nama},delete")->name('destroy');
        });
    }

    Route::middleware(['perlu-perusahaan', 'akses:pay-cutoff'])->prefix('cutoff')->name('cutoff.')->group(function () {
        Route::get('/', [Payroll\CutoffController::class, 'index'])->name('index');
        Route::put('/', [Payroll\CutoffController::class, 'update'])->middleware('akses:pay-cutoff,update')->name('update');
        Route::put('/prorata', [Payroll\CutoffController::class, 'prorata'])->middleware('akses:pay-cutoff,update')->name('prorata');
    });

    Route::middleware('akses:pay-kontrak')->prefix('kontrak')->name('kontrak.')->group(function () {
        Route::get('/', [Payroll\KontrakPayrollController::class, 'index'])->name('index');
        Route::post('/', [Payroll\KontrakPayrollController::class, 'store'])->middleware('akses:pay-kontrak,create')->name('store');
        Route::put('/{id}', [Payroll\KontrakPayrollController::class, 'ubah'])->whereNumber('id')->middleware('akses:pay-kontrak,update')->name('ubah');
        Route::post('/{id}/perbarui', [Payroll\KontrakPayrollController::class, 'perbarui'])->whereNumber('id')->middleware('akses:pay-kontrak,create')->name('perbarui');
        Route::post('/{id}/nonaktifkan', [Payroll\KontrakPayrollController::class, 'nonaktifkan'])->whereNumber('id')->middleware('akses:pay-kontrak,delete')->name('nonaktifkan');
    });

    // Fase 4: absensi / leave / lembur (manual) + import CSV
    foreach (['absensi', 'leave', 'lembur'] as $jenis) {
        Route::middleware("akses:pay-{$jenis}")->prefix($jenis)->name("{$jenis}.")->group(function () use ($jenis) {
            Route::get('/', [Payroll\KehadiranPayrollController::class, 'index'])->defaults('jenis', $jenis)->name('index');
            Route::post('/', [Payroll\KehadiranPayrollController::class, 'store'])->defaults('jenis', $jenis)->middleware("akses:pay-{$jenis},create")->name('store');
            Route::put('/{id}', [Payroll\KehadiranPayrollController::class, 'update'])->defaults('jenis', $jenis)->whereNumber('id')->middleware("akses:pay-{$jenis},update")->name('update');
            Route::delete('/{id}', [Payroll\KehadiranPayrollController::class, 'destroy'])->defaults('jenis', $jenis)->whereNumber('id')->middleware("akses:pay-{$jenis},delete")->name('destroy');
        });
    }

    Route::middleware('akses:pay-import')->prefix('import')->name('import.')->group(function () {
        Route::get('/', [Payroll\ImportPayrollController::class, 'index'])->name('index');
        Route::get('/template/{jenis}', [Payroll\ImportPayrollController::class, 'template'])->whereIn('jenis', ['absensi', 'leave', 'lembur'])->name('template');
        Route::post('/', [Payroll\ImportPayrollController::class, 'upload'])->middleware('akses:pay-import,create')->name('upload');
        Route::get('/{id}', [Payroll\ImportPayrollController::class, 'show'])->whereNumber('id')->name('show');
        Route::put('/{id}/baris/{baris}', [Payroll\ImportPayrollController::class, 'ubahBaris'])->whereNumber(['id', 'baris'])->middleware('akses:pay-import,update')->name('baris.update');
        Route::delete('/{id}/baris/{baris}', [Payroll\ImportPayrollController::class, 'hapusBaris'])->whereNumber(['id', 'baris'])->middleware('akses:pay-import,delete')->name('baris.destroy');
        Route::post('/{id}/simpan', [Payroll\ImportPayrollController::class, 'simpan'])->whereNumber('id')->middleware('akses:pay-import,create')->name('simpan');
        Route::post('/{id}/batal', [Payroll\ImportPayrollController::class, 'batal'])->whereNumber('id')->middleware('akses:pay-import,delete')->name('batal');
    });

    // Fase 5: tabel referensi + master payroll berversi
    Route::middleware(['perlu-perusahaan', 'akses:pay-referensi'])->prefix('referensi')->name('referensi.')->group(function () {
        $c = Payroll\ReferensiController::class;
        Route::get('/', [$c, 'index'])->name('index');
        Route::post('/', [$c, 'store'])->middleware('akses:pay-referensi,create')->name('store');
        Route::get('/versi/{id}', [$c, 'versi'])->whereNumber('id')->name('versi');
        Route::post('/versi/{id}/baris', [$c, 'simpanBaris'])->whereNumber('id')->middleware('akses:pay-referensi,update')->name('baris.store');
        Route::put('/versi/{id}/baris/{baris}', [$c, 'ubahBaris'])->whereNumber(['id', 'baris'])->middleware('akses:pay-referensi,update')->name('baris.update');
        Route::delete('/versi/{id}/baris/{baris}', [$c, 'hapusBaris'])->whereNumber(['id', 'baris'])->middleware('akses:pay-referensi,update')->name('baris.destroy');
        Route::post('/versi/{id}/terbitkan', [$c, 'terbitkan'])->whereNumber('id')->middleware('akses:pay-referensi,update')->name('terbitkan');
        Route::post('/versi/{id}/salin', [$c, 'salin'])->whereNumber('id')->middleware('akses:pay-referensi,create')->name('salin');
    });

    Route::middleware('akses:pay-master')->prefix('master')->name('master.')->group(function () {
        $c = Payroll\MasterPayrollController::class;
        Route::get('/', [$c, 'index'])->name('index');
        Route::post('/', [$c, 'store'])->middleware('akses:pay-master,create')->name('store');
        Route::get('/versi/{id}', [$c, 'versi'])->whereNumber('id')->name('versi');
        Route::post('/versi/{id}/{jenis}', [$c, 'simpanIsi'])->whereNumber('id')->whereIn('jenis', ['variabel', 'param', 'item'])->middleware('akses:pay-master,update')->name('isi.store');
        Route::delete('/versi/{id}/{jenis}/{isi}', [$c, 'hapusIsi'])->whereNumber(['id', 'isi'])->whereIn('jenis', ['variabel', 'param', 'item'])->middleware('akses:pay-master,update')->name('isi.destroy');
        Route::post('/versi/{id}/terbitkan', [$c, 'terbitkan'])->whereNumber('id')->middleware('akses:pay-master,update')->name('terbitkan');
        Route::delete('/{id}', [$c, 'destroy'])->whereNumber('id')->middleware('akses:pay-master,delete')->name('destroy');
        Route::post('/{id}/status', [$c, 'status'])->whereNumber('id')->middleware('akses:pay-master,update')->name('status');
        Route::delete('/versi/{id}', [$c, 'hapusVersi'])->whereNumber('id')->middleware('akses:pay-master,delete')->name('versi.destroy');
        Route::post('/versi/{id}/salin', [$c, 'salin'])->whereNumber('id')->middleware('akses:pay-master,create')->name('salin');
    });

    // Fase 6: alur payroll (preview, edit sel, generate, approval)
    Route::middleware('akses:pay-run')->prefix('run')->name('run.')->group(function () {
        $c = Payroll\RunController::class;
        Route::get('/', [$c, 'index'])->name('index');
        Route::post('/', [$c, 'store'])->middleware('akses:pay-run,create')->name('store');
        Route::get('/{id}', [$c, 'show'])->whereNumber('id')->name('show');
        Route::post('/{id}/pratinjau', [$c, 'pratinjau'])->whereNumber('id')->middleware('akses:pay-run,update')->name('pratinjau');
        Route::post('/{id}/penyesuaian', [$c, 'tambahPenyesuaian'])->whereNumber('id')->middleware('akses:pay-run,update')->name('penyesuaian.store');
        Route::delete('/{id}/penyesuaian/{penyesuaian}', [$c, 'hapusPenyesuaian'])->whereNumber(['id', 'penyesuaian'])->middleware('akses:pay-run,update')->name('penyesuaian.destroy');
        Route::post('/{id}/generate', [$c, 'generate'])->whereNumber('id')->middleware('akses:pay-run,create')->name('generate');
        Route::post('/{id}/tarik', [$c, 'tarikKembali'])->whereNumber('id')->middleware('akses:pay-run,update')->name('tarik');
        Route::post('/{id}/batalkan', [$c, 'batalkan'])->whereNumber('id')->middleware('akses:pay-run,update')->name('batalkan');
        Route::delete('/{id}', [$c, 'destroy'])->whereNumber('id')->middleware('akses:pay-run,delete')->name('destroy');
    });

    // dibuka oleh pemilik hak menu Approval ATAU penyetuju yang dipilih di Approver Payroll (AUDIT.md §77); setujui/tolak dicek di AlurPayroll
    Route::middleware('penyetuju-payroll')->prefix('approval')->name('approval.')->group(function () {
        $c = Payroll\ApprovalPayrollController::class;
        Route::get('/', [$c, 'index'])->name('index');
        Route::get('/{id}', [$c, 'show'])->whereNumber('id')->name('show');
        Route::post('/{id}/setujui', [$c, 'setujui'])->whereNumber('id')->name('setujui');
        Route::post('/{id}/tolak', [$c, 'tolak'])->whereNumber('id')->name('tolak');
    });

    Route::middleware('akses:pay-approver')->prefix('approver')->name('approver.')->group(function () {
        Route::get('/', [Payroll\ApproverController::class, 'index'])->name('index');
        Route::put('/pengaturan', [Payroll\ApproverController::class, 'pengaturan'])->middleware('akses:pay-approver,update')->name('pengaturan');
        Route::put('/penyetuju', [Payroll\ApproverController::class, 'penyetuju'])->middleware('akses:pay-approver,update')->name('penyetuju');
    });

    // Fase 7: slip gaji
    Route::middleware('akses:pay-slip')->prefix('slip')->name('slip.')->group(function () {
        Route::get('/', [Payroll\SlipPayrollController::class, 'index'])->name('index');
        Route::get('/{id}', [Payroll\SlipPayrollController::class, 'show'])->whereNumber('id')->name('show');
        Route::get('/{id}/unduh', [Payroll\SlipPayrollController::class, 'unduh'])->whereNumber('id')->name('unduh');
    });
});
