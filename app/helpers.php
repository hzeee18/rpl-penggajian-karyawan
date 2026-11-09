<?php

use Carbon\Carbon;

if (! function_exists('rupiah')) {
    function rupiah($angka): string
    {
        return 'Rp ' . number_format((float) $angka, 0, ',', '.');
    }
}

if (! function_exists('tgl')) {
    /** Format tanggal Indonesia, mis. 7 November 2026. */
    function tgl($tanggal, string $format = 'j F Y'): string
    {
        return $tanggal ? Carbon::parse($tanggal)->translatedFormat($format) : '-';
    }
}

if (! function_exists('nama_periode')) {
    function nama_periode(string $periode): string
    {
        return Carbon::createFromFormat('Y-m-d', $periode . '-01')->translatedFormat('F Y');
    }
}

if (! function_exists('badge_status')) {
    /** Badge warna lembut sesuai komponen Badge di Figma. */
    function badge_status(?string $status): string
    {
        $warna = [
            'draft' => 'neutral', 'menunggu' => 'warning', 'disetujui' => 'success', 'final' => 'success',
            'ditolak' => 'danger', 'aktif' => 'success', 'nonaktif' => 'neutral', 'hadir' => 'success',
            'terlambat' => 'warning', 'alfa' => 'danger', 'cuti' => 'info', 'izin' => 'primary', 'sakit' => 'warning',
            'terbit' => 'success', 'terkunci' => 'danger', 'dilewati' => 'neutral', 'penambah' => 'success', 'pengurang' => 'danger',
        ][$status] ?? 'neutral';

        return '<span class="badge badge-soft-' . $warna . '">' . e(ucfirst((string) $status)) . '</span>';
    }
}

if (! function_exists('inisial')) {
    /** Dua huruf inisial nama, mis. "Dewi Anggraini" -> "DA". */
    function inisial(?string $nama): string
    {
        $kata = preg_split('/\s+/', trim((string) $nama)) ?: [];
        $hasil = mb_substr($kata[0] ?? '?', 0, 1) . (isset($kata[1]) ? mb_substr($kata[1], 0, 1) : mb_substr($kata[0] ?? '', 1, 1));

        return mb_strtoupper($hasil);
    }
}

if (! function_exists('orang')) {
    /** Avatar inisial + nama + keterangan kecil (pola kolom Karyawan di tabel Figma). */
    function orang(?string $nama, ?string $sub = null, string $ukuran = 'avatar-sm', ?string $avatarDari = null): string
    {
        return '<div class="orang"><span class="avatar ' . $ukuran . '">' . e(inisial($avatarDari ?? $nama)) . '</span><div><div class="nama">'
            . e($nama) . '</div>' . ($sub ? '<div class="sub">' . e($sub) . '</div>' : '') . '</div></div>';
    }
}

if (! function_exists('jt')) {
    /** Format ringkas jutaan, mis. 4.800.000 -> "4,8 jt". */
    function jt($angka): string
    {
        $n = (float) $angka;

        return $n >= 1000000 ? rtrim(rtrim(number_format($n / 1000000, 1, ',', '.'), '0'), ',') . ' jt' : number_format($n, 0, ',', '.');
    }
}

if (! function_exists('crud')) {
function crud(string $nama, string $controller, bool $show = false, ?string $param = null): void
{
    $param ??= $nama;
    \Illuminate\Support\Facades\Route::middleware('akses:' . $nama)->group(function () use ($nama, $controller, $show, $param) {
        \Illuminate\Support\Facades\Route::get("/{$nama}", [$controller, 'index'])->name("{$nama}.index");
        \Illuminate\Support\Facades\Route::get("/{$nama}/create", [$controller, 'create'])->middleware("akses:{$nama},create")->name("{$nama}.create");
        \Illuminate\Support\Facades\Route::post("/{$nama}", [$controller, 'store'])->middleware("akses:{$nama},create")->name("{$nama}.store");
        if ($show) {
            \Illuminate\Support\Facades\Route::get("/{$nama}/{{$param}}", [$controller, 'show'])->name("{$nama}.show");
        }
        \Illuminate\Support\Facades\Route::get("/{$nama}/{{$param}}/edit", [$controller, 'edit'])->middleware("akses:{$nama},update")->name("{$nama}.edit");
        \Illuminate\Support\Facades\Route::put("/{$nama}/{{$param}}", [$controller, 'update'])->middleware("akses:{$nama},update")->name("{$nama}.update");
        \Illuminate\Support\Facades\Route::delete("/{$nama}/{{$param}}", [$controller, 'destroy'])->middleware("akses:{$nama},delete")->name("{$nama}.destroy");
    });
}
}
