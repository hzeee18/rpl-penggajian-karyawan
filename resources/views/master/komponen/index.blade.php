@extends('layouts.app')
@section('title', 'Komponen Gaji')
@section('breadcrumb', 'Master Data / Komponen Gaji')
@section('content')
<x-page-header judul="Komponen Gaji" sub="Tiap komponen punya kode dan rumus. Rumus bisa pakai variabel bawaan dan kode komponen lain.">
    <a href="{{ route('komponen.index', ['baru' => 1]) }}" class="btn btn-primary"><i data-lucide="plus"></i> Tambah Komponen</a>
</x-page-header>

<div class="card">
    <x-cari placeholder="Cari kode atau nama komponen..." />
    <div class="table-responsive">
        <table class="table table-hover">
            <thead><tr><th>Kode</th><th>Komponen</th><th>Jenis</th><th>Rumus</th><th>Status</th><th>Aksi</th></tr></thead>
            <tbody>
            @foreach ($komponen as $k)
                <tr class="{{ $ubah->id === $k->id ? 'terpilih' : '' }}">
                    <td class="kode">{{ $k->kode }}</td>
                    <td><div class="fw-semibold">{{ $k->nama }}</div><div class="small text-muted">{{ $k->keterangan() }}</div></td>
                    <td>{!! badge_status($k->jenis) !!}</td>
                    <td>@if ($k->rumus)<code class="rumus">{{ $k->rumus }}</code>@else<span class="text-muted">{{ $k->nilaiRingkas() }}</span>@endif</td>
                    <td>@if ($k->status_berlaku === 'aktif')<span class="badge badge-soft-success">Aktif</span>@else<span class="badge badge-soft-neutral">Nonaktif</span>@endif</td>
                    <td class="text-nowrap">@include('partials.aksi', array_filter(['ubah' => route('komponen.index', ['ubah' => $k->id]), 'hapus' => ($k->isSistem() || $k->dasar_bayar === 'alfa') ? null : route('komponen.destroy', $k)]))</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

@php
    $sistem = $ubah->isSistem();
    // kode komponen lain yang bisa dirujuk; GP sudah ada di variabel bawaan jadi tidak dobel
    $kodeLain = $komponen->reject(fn ($c) => $c->id === $ubah->id || $c->dasar_bayar === 'pajak' || array_key_exists(strtoupper($c->kode), \App\Support\Rumus::VARIABEL))->pluck('nama', 'kode');
@endphp
<x-panel :judul="$ubah->exists ? 'Ubah Komponen' : 'Tambah Komponen'" :sub="$ubah->exists ? $ubah->kode . ' · ' . $ubah->nama : 'Komponen baru'"
         :action="$ubah->exists ? route('komponen.update', $ubah) : route('komponen.store')" :method="$ubah->exists ? 'put' : 'post'">
    <div class="row g-2 mb-field">
        <div class="col-4"><label class="form-label">Kode</label><input name="kode" id="kodeKomp" value="{{ old('kode', $ubah->kode) }}" class="form-control text-uppercase" maxlength="10" required @readonly($sistem)></div>
        <div class="col-8"><label class="form-label">Nama</label><input name="nama" value="{{ old('nama', $ubah->nama) }}" class="form-control" required></div>
    </div>
    <div class="row g-2 mb-field">
        <div class="col-6"><label class="form-label">Jenis</label>
            <select name="jenis" class="form-select">@foreach (['penambah' => 'Penambah', 'pengurang' => 'Pengurang'] as $v => $l)<option value="{{ $v }}" @selected(old('jenis', $ubah->jenis) === $v)>{{ $l }}</option>@endforeach</select></div>
        <div class="col-6"><label class="form-label">Berlaku untuk</label>
            @if ($sistem || $ubah->dasar_bayar === 'alfa')
                <input class="form-control" value="Semua karyawan" disabled>
            @else
                <select name="berlaku" class="form-select"><option value="kontrak" @selected(old('berlaku', $ubah->dasar_bayar === 'semua' ? 'semua' : 'kontrak') === 'kontrak')>Dipasang di kontrak</option><option value="semua" @selected(old('berlaku', $ubah->dasar_bayar === 'semua' ? 'semua' : 'kontrak') === 'semua')>Semua karyawan</option></select>
            @endif
        </div>
    </div>

    @if ($sistem)
        <div class="mb-field"><x-catatan tipe="info">{{ $ubah->dasar_bayar === 'gaji_pokok' ? 'Gaji pokok diambil dari kontrak masing-masing karyawan. Pakai kode GP di rumus komponen lain.' : 'PPh21 dihitung dari tarif TER di menu Pajak & Tarif TER.' }}</x-catatan></div>
    @else
        <div class="mb-field">
            <label class="form-label">Rumus</label>
            <textarea name="rumus" id="rumusKomp" rows="2" class="form-control font-monospace" required placeholder="mis. GP * 15% / HK * HADIR">{{ old('rumus', $ubah->rumus) }}</textarea>
            <div class="kalkulator mt-2">
                <div class="baris-kalk">
                    @foreach (['+' => '+', '-' => '−', '*' => '×', '/' => '÷', '(' => '(', ')' => ')', '%' => '%', ',' => ','] as $isi => $tampil)<button type="button" class="tombol-kalk op" data-sisip="{{ $isi }}" data-op title="{{ $isi }}">{{ $tampil }}</button>@endforeach
                    <button type="button" class="tombol-kalk op" data-hapus title="Hapus satu bagian terakhir"><i data-lucide="delete"></i></button>
                </div>
                <div class="judul-kalk">Fungsi</div>
                <div class="baris-kalk">
                    @foreach (['SUM' => 'SUM(a, b, …) jumlahkan', 'MIN' => 'MIN(a, b) nilai terkecil', 'MAX' => 'MAX(a, b) nilai terbesar', 'ROUND' => 'ROUND(x, digit) bulatkan', 'IF' => 'IF(kondisi, ya, tidak), mis. IF(ALFA = 0, 100.000, 0)'] as $f => $ket)<button type="button" class="tombol-kalk" data-sisip="{{ $f }}(" data-fungsi title="{{ $ket }}">{{ $f }}( )</button>@endforeach
                </div>
                <div class="judul-kalk">Variabel</div>
                <div class="baris-kalk">
                    @foreach (\App\Support\Rumus::VARIABEL as $v => $ket)<button type="button" class="tombol-kalk" data-sisip="{{ $v }}" title="{{ $ket }}">{{ $v }}</button>@endforeach
                </div>
                @if ($kodeLain->isNotEmpty())
                    <div class="judul-kalk">Komponen lain</div>
                    <div class="baris-kalk">
                        @foreach ($kodeLain as $kd => $nm)<button type="button" class="tombol-kalk" data-sisip="{{ $kd }}" title="{{ $nm }}">{{ $kd }}</button>@endforeach
                    </div>
                @endif
                <div class="form-text mt-2">Angka diketik langsung, ribuan pakai titik (500.000). 15% = 0,15. Arahkan kursor ke tombol untuk lihat artinya.</div>
            </div>
        </div>
        <div class="mb-field pratinjau-rumus" data-url="{{ route('komponen.pratinjau') }}" data-id="{{ $ubah->id }}">
            <div class="d-flex justify-content-between align-items-center mb-2 gap-2">
                <span class="fw-semibold small">Pratinjau</span>
                <select class="form-select form-select-sm" id="contohKaryawan" style="max-width:220px">@foreach ($contoh as $c)<option value="{{ $c->id }}">{{ $c->nama }}</option>@endforeach</select>
            </div>
            <div class="hasil"><span class="text-muted small">Ketik rumus untuk melihat hasilnya.</span></div>
        </div>
        <div class="mb-field"><label class="form-label">Nilai khusus default <span class="text-muted fw-normal">(opsional, variabel NILAI)</span></label>
            <div class="input-group"><span class="input-group-text bg-white">Rp</span><input type="number" min="0" step="1" name="nilai_default" value="{{ old('nilai_default', ($ubah->nilai_default ?? 0) + 0) }}" class="form-control"></div>
            <div class="form-text">Dipakai kalau rumus menyebut NILAI, mis. tunjangan jabatan yang beda per kontrak.</div></div>
    @endif

    <div class="row g-2 mb-field">
        <div class="col-6"><label class="form-label">Status</label><select name="status_berlaku" class="form-select">@foreach (['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif'] as $v => $l)<option value="{{ $v }}" @selected(old('status_berlaku', $ubah->status_berlaku) === $v)>{{ $l }}</option>@endforeach</select></div>
        <div class="col-6 d-flex align-items-end"><div class="form-check mb-2"><input type="checkbox" class="form-check-input" name="kena_pajak" value="1" id="kp" @checked(old('kena_pajak', $ubah->kena_pajak))><label class="form-check-label" for="kp">Kena pajak</label></div></div>
    </div>
    <div class="tombol"><a href="{{ route('komponen.index') }}" class="btn btn-light">Batal</a><button class="btn btn-primary">Simpan</button></div>
</x-panel>
@endsection
