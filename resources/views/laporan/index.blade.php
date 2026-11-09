@extends('layouts.app')
@section('title', 'Riwayat Payroll')
@section('breadcrumb', 'Payroll / Riwayat Payroll')
@section('content')
@php
    $statusOpsi = ['final' => 'Final', 'menunggu' => 'Menunggu approval', 'ditolak' => 'Ditolak', 'draft' => 'Draft'];
@endphp
<x-page-header judul="Riwayat Payroll" sub="Semua periode yang pernah diproses. Klik satu periode untuk lihat rincian, rekap per departemen, dan export.">
    @if ($final)
        <a href="{{ route('laporan.excel', ['periode' => $final->periode]) }}" class="btn btn-light"><i data-lucide="file-down"></i> Excel {{ $final->namaPeriode() }}</a>
    @endif
</x-page-header>

<div class="card">
    <form class="card-search" method="get" data-auto-submit>
        <div class="filter-bar filter-bar-2">
            <div><label class="form-label">Tahun</label>
                <select name="tahun" class="form-select" onchange="this.form.requestSubmit()"><option value="">Semua tahun</option>@foreach ($tahunAda as $t)<option value="{{ $t }}" @selected($tahun == $t)>{{ $t }}</option>@endforeach</select></div>
            <div><label class="form-label">Status</label>
                <select name="status[]" multiple class="form-select" data-placeholder="Semua status" data-satuan="status">@foreach ($statusOpsi as $v => $l)<option value="{{ $v }}" @selected(in_array($v, $status))>{{ $l }}</option>@endforeach</select></div>
        </div>
    </form>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead><tr><th>Periode</th><th>Status</th><th class="text-center">Karyawan</th><th class="text-end">Total bruto</th><th class="text-end">Total gaji bersih</th><th>Disetujui</th><th>Aksi</th></tr></thead>
            <tbody>
            @forelse ($daftar as $p)
                @php $r = $ringkas[$p->id]; @endphp
                <tr>
                    <td><div class="fw-semibold">{{ $p->namaPeriode() }}</div>@if ($p->master)<div class="small text-muted">{{ tgl($p->master->tgl_mulai, 'j M') }} – {{ tgl($p->master->tgl_selesai, 'j M Y') }}</div>@endif</td>
                    <td>{!! badge_status($p->status) !!}</td>
                    <td class="text-center">{{ $r['karyawan'] }}</td>
                    <td class="text-uang">{{ rupiah($r['bruto']) }}</td>
                    <td class="text-uang fw-semibold">{{ rupiah($r['thp']) }}</td>
                    <td>@if ($r['oleh']){{ $r['oleh'] }}<div class="small text-muted">{{ tgl($r['tgl'], 'j M Y') }}</div>@else<span class="text-muted">-</span>@endif</td>
                    <td class="text-nowrap">
                        <a href="{{ route('laporan.show', $p) }}" class="btn btn-light btn-icon" title="Lihat rincian"><i data-lucide="eye"></i></a>
                        @if ($p->status === 'final')<a href="{{ route('laporan.excel', ['periode' => $p->periode]) }}" class="btn btn-light btn-icon" title="Export Excel"><i data-lucide="download"></i></a>@endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7"><x-empty ikon="history" judul="Belum ada riwayat payroll" pesan="Periode yang sudah di-generate dari Proses Payroll akan muncul di sini." /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if ($daftar->lastPage() > 1) @include('partials.halaman', ['data' => $daftar, 'label' => 'periode']) @endif
</div>
@endsection
