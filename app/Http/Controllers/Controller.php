<?php

namespace App\Http\Controllers;

use App\Models\Karyawan;
use App\Services\PayrollService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

abstract class Controller
{
    /** Daftar karyawan yang boleh dipilih user (Manager: tim sendiri). */
    protected function pilihanKaryawan(Request $request)
    {
        return $request->user()->batasi(Karyawan::where('is_active', true), 'id')->orderBy('nama')->get();
    }

    /** Pastikan karyawan masuk lingkup user. */
    protected function pastikanLingkup(Request $request, int $karyawanId): void
    {
        $ids = $request->user()->karyawanIds();
        if ($ids !== null && ! in_array($karyawanId, $ids)) {
            throw ValidationException::withMessages(['karyawan_id' => 'Karyawan ini bukan anggota tim Anda.']);
        }
    }

    /** Tolak perubahan data di periode payroll yang sudah diajukan/final. */
    protected function pastikanTidakTerkunci(...$tanggal): void
    {
        $svc = app(PayrollService::class);
        foreach (array_filter($tanggal) as $t) {
            if ($svc->tanggalTerkunci($t)) {
                throw ValidationException::withMessages(['tanggal' => 'Tanggal ' . tgl($t) . ' masuk periode payroll yang sudah diajukan/final, data tidak bisa diubah.']);
            }
        }
    }

    /** Kembali ke halaman sebelumnya (tanpa ?ubah/?baru supaya drawer tertutup) dengan toast. */
    protected function kembali(string $pesan, string $tipe = 'sukses')
    {
        $u = parse_url(url()->previous());
        parse_str($u['query'] ?? '', $q);
        unset($q['ubah'], $q['baru'], $q['lihat'], $q['perbarui']);
        $url = ($u['scheme'] ?? 'http') . '://' . ($u['host'] ?? 'localhost') . (isset($u['port']) ? ':' . $u['port'] : '') . ($u['path'] ?? '/') . ($q ? '?' . http_build_query($q) : '');

        return redirect()->to($url)->with($tipe, $pesan);
    }

    /** Filter periode (YYYY-MM) default bulan ini, dalam rentang cut-off master payroll. */
    protected function rentangFilter(Request $request): array
    {
        $periode = $request->input('periode') ?: now()->format('Y-m');
        [$awal, $akhir] = \App\Models\MasterPayroll::aktif()->rentang($periode);

        return [$periode, $awal, $akhir];
    }
}
