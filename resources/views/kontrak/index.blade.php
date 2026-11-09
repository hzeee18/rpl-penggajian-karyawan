@extends('layouts.app')
@section('title', 'Kontrak Karyawan')
@section('breadcrumb', 'Karyawan / Kontrak')
@section('content')
@php
    $statusLabel = ['berakhir' => ['Segera berakhir', 'badge-soft-warning'], 'menunggu' => ['Perpanjangan diajukan', 'badge-soft-info'], 'ditolak' => ['Perpanjangan ditolak', 'badge-soft-danger'], 'lanjut' => ['Sudah diperpanjang', 'badge-soft-info'], 'aktif' => ['Aktif', 'badge-soft-success'], 'berakhir_lewat' => ['Tidak aktif', 'badge-soft-neutral']];
    $chip = fn ($s) => route('kontrak.index', array_filter(['status' => $s, 'q' => request('q')]));
@endphp
<x-page-header judul="Kontrak Karyawan" sub="Satu baris per karyawan. Kontrak baru dan perpanjangan otomatis diajukan ke Manager divisi.">
    <a href="{{ route('kontrak.index', array_filter(['baru' => 1, 'q' => request('q'), 'status' => request('status')])) }}" class="btn btn-primary"><i data-lucide="plus"></i> Kontrak Karyawan Baru</a>
</x-page-header>

<div class="card">
    <div class="card-search d-flex flex-wrap gap-2 align-items-center">
        <form method="get" class="flex-grow-1" style="max-width:420px">
            @if (request('status'))<input type="hidden" name="status" value="{{ request('status') }}">@endif
            <div class="input-ikon"><input name="q" value="{{ request('q') }}" class="form-control" placeholder="Cari nama, NIK, atau nomor kontrak..."><i data-lucide="search"></i></div>
        </form>
        <div class="chips ms-auto">
            <a href="{{ $chip(null) }}" class="chip {{ request('status') ? '' : 'active' }}">Semua <b>{{ $hitung->sum() }}</b></a>
            <a href="{{ $chip('berakhir') }}" class="chip {{ request('status') === 'berakhir' ? 'active' : '' }}">Segera berakhir <b>{{ $hitung['berakhir'] ?? 0 }}</b></a>
            <a href="{{ $chip('menunggu') }}" class="chip {{ request('status') === 'menunggu' ? 'active' : '' }}">Diajukan <b>{{ $hitung['menunggu'] ?? 0 }}</b></a>
            <a href="{{ $chip('ditolak') }}" class="chip {{ request('status') === 'ditolak' ? 'active' : '' }}">Ditolak <b>{{ $hitung['ditolak'] ?? 0 }}</b></a>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead><tr><th>Nomor kontrak</th><th>Karyawan</th><th>Jenis</th><th>Masa berlaku</th><th class="text-end">Gaji pokok</th><th>Status</th><th>Aksi</th></tr></thead>
            <tbody>
            @forelse ($daftar as $b)
                @php $k = $b->kontrak; $sisa = $k->sisaHari(); [$lbl, $cls] = $statusLabel[$b->status]; @endphp
                <tr class="{{ $lihat?->karyawan_id === $b->karyawan->id ? 'terpilih' : '' }}">
                    <td><span class="kode">{{ $k->kode }}</span>@if ($b->jumlah > 1)<div class="small text-muted">{{ $b->jumlah }} kontrak</div>@endif</td>
                    <td>{!! orang($b->karyawan->nama, $b->karyawan->nik . ' · ' . ($b->karyawan->departemen?->nama ?? '-')) !!}</td>
                    <td>{{ $k->jenis }}</td>
                    <td class="text-nowrap">{{ tgl($k->tgl_mulai, 'j M Y') }} – {{ $k->tgl_akhir ? tgl($k->tgl_akhir, 'j M Y') : 'tetap' }}
                        @if ($k->is_active && $sisa !== null)<div class="small {{ $sisa <= 90 ? 'text-warning-emphasis fw-semibold' : 'text-muted' }}">{{ $sisa >= 0 ? 'sisa ' . ($sisa > 60 ? round($sisa / 30) . ' bulan' : $sisa . ' hari') : 'sudah lewat' }}</div>@endif
                    </td>
                    <td class="text-uang">{{ jt($k->gaji_pokok) }}</td>
                    <td><span class="badge {{ $cls }}">{{ $lbl }}</span>
                        @if ($b->proses)<div class="small text-muted mt-1">{{ $b->proses->kode }} · mulai {{ tgl($b->proses->tgl_mulai, 'j M Y') }}</div>@endif
                    </td>
                    <td class="text-nowrap">
                        <a href="{{ route('kontrak.index', array_filter(['lihat' => $k->id, 'q' => request('q'), 'status' => request('status'), 'page' => request('page')])) }}" class="btn btn-light btn-icon" title="Lihat detail & riwayat"><i data-lucide="eye"></i></a>
                        @if ($k->bisaDiperbarui())
                            <a href="{{ route('kontrak.index', array_filter(['perbarui' => $k->id, 'q' => request('q'), 'status' => request('status'), 'page' => request('page')])) }}" class="btn btn-primary btn-sm"><i data-lucide="refresh-cw"></i> Perbarui</a>
                        @elseif ($b->proses?->bisaDiubah())
                            <a href="{{ route('kontrak.index', array_filter(['ubah' => $b->proses->id, 'q' => request('q'), 'status' => request('status')])) }}" class="btn btn-light btn-sm"><i data-lucide="pencil"></i> Revisi</a>
                        @elseif ($k->bisaDiubah())
                            <a href="{{ route('kontrak.index', array_filter(['ubah' => $k->id, 'q' => request('q'), 'status' => request('status')])) }}" class="btn btn-light btn-sm"><i data-lucide="pencil"></i> Revisi</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7"><x-empty ikon="file-text" judul="Tidak ada kontrak" pesan="Coba ubah kata kunci atau filter status." /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if ($daftar->lastPage() > 1) @include('partials.halaman', ['data' => $daftar, 'label' => 'karyawan']) @endif
</div>

@if ($lihat)
    @include('kontrak._lihat')
@else
    @include('kontrak._panel')
@endif
@endsection
