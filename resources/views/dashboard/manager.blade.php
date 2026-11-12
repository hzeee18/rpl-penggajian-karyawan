@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
<x-page-header judul="Dashboard Manager" :sub="($dept ? 'Manager Departemen ' . $dept->nama : 'Manager') . ' · ' . ($jumlahTim) . ' anggota tim'">
    <a href="{{ route('approval.index') }}" class="btn btn-primary"><i data-lucide="circle-check"></i> Buka Approval</a>
</x-page-header>

@include('dashboard._stat')

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Perlu Persetujuan Anda<span class="sub">{{ auth()->user()->isManagerHr() ? 'Payroll semua departemen dan kontrak tim' : 'Kontrak karyawan divisi Anda' }}</span></span>
                <a href="{{ route('approval.index') }}" class="fw-semibold small">Lihat semua</a>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Pengaju</th><th>Jenis</th><th>Detail</th><th class="text-end">Aksi</th></tr></thead>
                    <tbody>
                    @forelse ($approvals as $a)
                        @php $ref = $a->ref(); @endphp
                        <tr>
                            <td>{!! orang($a->jenis_ref === 'kontrak' ? $ref?->karyawan?->nama : 'HR', 'Diajukan · ' . $a->created_at->translatedFormat('j M')) !!}</td>
                            <td><span class="badge badge-soft-primary">{{ ucfirst($a->jenis_ref) }}</span></td>
                            <td class="text-secondary">
                                @if ($a->jenis_ref === 'kontrak' && $ref) {{ $ref->jenis }} · {{ tgl($ref->tgl_mulai, 'j M Y') }} – {{ $ref->tgl_akhir ? tgl($ref->tgl_akhir, 'j M Y') : 'tetap' }}{{ auth()->user()->bolehLihatGaji() ? ' · GP Rp ' . jt($ref->gaji_pokok) : '' }}
                                @elseif ($ref) Payroll {{ $ref->namaPeriode() }} @endif
                            </td>
                            <td class="text-end text-nowrap">
                                <a href="{{ route('approval.show', $a) }}" class="btn btn-setuju btn-icon" title="Review & setujui"><i data-lucide="check"></i></a>
                                <a href="{{ route('approval.show', $a) }}" class="btn btn-tolak btn-icon" title="Review & tolak"><i data-lucide="x"></i></a>
                                <a href="{{ route('approval.show', $a) }}" class="btn btn-light btn-icon" title="Lihat"><i data-lucide="eye"></i></a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4"><x-empty ikon="clipboard-check" judul="Semua sudah beres" pesan="Tidak ada kontrak atau payroll yang menunggu approval kamu." /></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">Kehadiran Tim Hari Ini<span class="sub">{{ now()->translatedFormat('l, j F Y') }}</span></div>
            <div class="card-body">
                @foreach ($hariIniBar as [$label, $n, $warna])
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1"><span>{{ $label }}</span><span class="small text-secondary">{{ $n }} orang</span></div>
                        <div class="bar"><span style="width:{{ max(round($n / $jumlahTim * 100), $n ? 4 : 2) }}%;background:{{ $warna }}"></span></div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Lembur Tim Bulan Ini<span class="sub">{{ ($payrollBaru ?? false) ? 'Karyawan dengan kontrak payroll aktif' : 'Hanya karyawan yang kontraknya memiliki komponen lembur' }}</span></span>
    </div>
    <div class="table-responsive">
        <table class="table">
            @php $gajiOk = auth()->user()->bolehLihatGaji(); @endphp
            <thead><tr><th>Karyawan</th><th>Total jam</th>@if ($gajiOk)<th class="text-end">Estimasi upah lembur</th>@endif<th>Sumber</th></tr></thead>
            <tbody>
            @forelse ($lembur as $l)
                <tr>
                    <td>{!! orang($l->karyawan->nama, $l->karyawan->jabatan) !!}</td>
                    <td>{{ $l->jam + 0 }} jam</td>
                    @if ($gajiOk)<td class="text-uang">{{ rupiah($l->upah) }}</td>@endif
                    <td><span class="badge badge-soft-neutral">{{ $l->sumber }}</span></td>
                </tr>
            @empty
                <tr><td colspan="4"><x-empty ikon="clock" judul="Belum ada lembur periode ini" pesan="Lembur yang kamu input untuk tim akan tercatat di sini." /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
