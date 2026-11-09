@extends('layouts.app')
@section('title', 'Payroll ' . $payroll->namaPeriode())
@section('breadcrumb', 'Payroll / Riwayat Payroll')
@section('content')
@php
    $final = $payroll->status === 'final';
    $q = array_filter(['departemen_id' => $departemenId]);
    $u = auth()->user();
@endphp
<x-page-header :judul="'Payroll ' . $payroll->namaPeriode()" :status="$payroll->status" :sub="($payroll->master ? tgl($payroll->master->tgl_mulai, 'j M') . ' – ' . tgl($payroll->master->tgl_selesai, 'j M Y') . ' · ' : '') . 'Di-generate ' . tgl($payroll->tgl_generate) . ' · ' . $semua->count() . ' karyawan'">
    <a href="{{ route('laporan.index') }}" class="btn btn-light"><i data-lucide="arrow-left"></i> Riwayat</a>
    @if ($final)
        <a href="{{ route('laporan.pdf', ['periode' => $payroll->periode] + $q) }}" class="btn btn-light"><i data-lucide="printer"></i> Cetak PDF</a>
        <a href="{{ route('laporan.excel', ['periode' => $payroll->periode] + $q) }}" class="btn btn-primary"><i data-lucide="file-down"></i> Export Excel</a>
    @elseif ($payroll->status === 'draft' && $u->bisa('payroll', 'update'))
        <form method="post" action="{{ route('payroll.ajukan', $payroll) }}" data-confirm="Ajukan payroll ini ke Manager HR? Setelah diajukan data tidak bisa diubah.">@csrf<button class="btn btn-primary"><i data-lucide="send"></i> Ajukan Approval</button></form>
    @endif
</x-page-header>

@if ($payroll->status === 'ditolak' && ($tolak = $payroll->approvals->firstWhere('status', 'ditolak')))
    <div class="mb-3"><x-catatan tipe="danger">Ditolak {{ $tolak->approver?->namaTampil() }}: {{ $tolak->catatan }}. Perbaiki data di Proses Payroll lalu generate ulang.</x-catatan></div>
@elseif ($payroll->status === 'menunggu')
    <div class="mb-3"><x-catatan tipe="info">Payroll ini sedang menunggu approval Manager HR. Export dan slip gaji tersedia setelah disetujui.</x-catatan></div>
@endif

@php
    $kartu = collect($total)->reject(fn ($v, $l) => $v == 0 && in_array($l, ['THR & tunjangan lain', 'Potongan lain']));
@endphp
<div class="stat-row sebaris {{ $kartu->count() + 1 > 6 ? 'padat' : '' }}">
    @foreach ($kartu as $l => $v)
        <div class="card stat-card"><div class="card-body"><div class="label">{{ $l }}</div><div class="angka">{{ $v < 0 ? '- ' : '' }}{{ rupiah(abs($v)) }}</div></div></div>
    @endforeach
    <div class="card stat-card utama"><div class="card-body"><div class="label">Gaji bersih</div><div class="angka text-primary">{{ rupiah($semua->sum('thp')) }}</div></div></div>
</div>

<div class="card mb-4">
    <div class="card-header">Rekap per departemen</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Kode</th><th>Departemen / Manager</th><th class="text-center">Karyawan</th><th class="text-end">Gaji bersih</th><th>Aksi</th></tr></thead>
            <tbody>
            @foreach ($perDept as $d)
                @php $dept = $departemen->firstWhere('id', $d->dept?->id); @endphp
                <tr class="{{ $departemenId === $d->dept?->id ? 'terpilih' : '' }}">
                    <td class="kode">{{ $d->dept?->kode }}</td>
                    <td><div class="fw-semibold">{{ $d->dept?->nama }}</div><div class="small text-muted">{{ $dept?->namaManager() ?? 'Belum ada manager' }}</div></td>
                    <td class="text-center">{{ $d->jumlah }}</td>
                    <td class="text-uang fw-semibold">{{ rupiah($d->thp) }}</td>
                    <td class="text-nowrap">
                        <a href="{{ route('laporan.show', [$payroll, 'departemen_id' => $d->dept?->id]) }}#karyawan" class="btn btn-light btn-icon" title="Lihat karyawan departemen ini"><i data-lucide="eye"></i></a>
                        @if ($final)<a href="{{ route('laporan.excel', ['periode' => $payroll->periode, 'departemen_id' => $d->dept?->id]) }}" class="btn btn-light btn-icon" title="Unduh Excel"><i data-lucide="download"></i></a>@endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="card mb-4" id="karyawan">
    <form class="card-search d-flex flex-wrap gap-2 align-items-center" method="get">
        <span class="fw-semibold me-auto">Rincian karyawan</span>
        <select name="departemen_id" class="form-select" style="width:240px" onchange="this.form.requestSubmit()"><option value="">Semua departemen</option>@foreach ($departemen as $d)<option value="{{ $d->id }}" @selected($departemenId === $d->id)>{{ $d->nama }}</option>@endforeach</select>
    </form>
    @include('payroll._tabel', ['link' => true])
</div>

@if ($payroll->approvals->isNotEmpty())
<div class="card">
    <div class="card-header">Riwayat approval</div>
    <div class="card-body">
        <x-riwayat-approval :approvals="$payroll->approvals" />
    </div>
</div>
@endif
@endsection
