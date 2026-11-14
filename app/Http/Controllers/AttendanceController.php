<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Menu "Absensi": riwayat kehadiran milik karyawan sendiri dari modul payroll (AUDIT.md §68).
 * Input absensi/leave/lembur oleh HR ada di modul payroll (/pay/absensi, /pay/leave, /pay/lembur).
 */
class AttendanceController extends Controller
{
    public function index(Request $request)
    {
        return $this->riwayatSaya($request);
    }

    /** Riwayat karyawan sendiri dari tabel modul payroll baru (periode cut off perusahaan). */
    private function riwayatSaya(Request $request)
    {
        $k = $request->user()->karyawan;
        if (! $k) {
            return view('absensi.tanpa-karyawan'); // akun belum dihubungkan ke data karyawan
        }
        $periode = preg_match('/^\d{4}-\d{2}$/', (string) $request->input('periode')) ? $request->input('periode') : now()->format('Y-m');
        [$awal, $akhir] = \App\Domain\Payroll\KehadiranKaryawan::rentang($periode);
        ['rekap' => $rekap, 'baris' => $semua] = \App\Domain\Payroll\KehadiranKaryawan::untuk($k->id, $awal, $akhir);
        $filter = $request->input('filter');
        $baris = $semua->filter(fn ($r) => match ($filter) {
            'hadir' => in_array($r->status, ['hadir', 'terlambat'], true),
            'leave' => $r->status === 'leave',
            'lembur' => bccomp($r->lembur, '0', 2) > 0,
            default => true,
        })->values();

        return view('absensi.saya', compact('periode', 'awal', 'akhir', 'rekap', 'baris') + ['jumlah' => $semua->count(), 'karyawan' => $k]);
    }
}
