@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
<x-page-header judul="Dashboard Manager" :sub="($dept ? 'Manager Departemen ' . $dept->nama : 'Manager') . ' · ' . $jumlahTim . ' anggota tim'">
    @if (auth()->user()->bisa('pay-approval'))<a href="{{ route('pay.approval.index') }}" class="btn btn-primary"><i data-lucide="circle-check"></i> Buka Approval Payroll</a>@endif
</x-page-header>

@include('dashboard._stat')

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Payroll Menunggu Persetujuan Anda<span class="sub">Disetujui atau ditolak di menu Approval Payroll</span></span>
                @if (auth()->user()->bisa('pay-approval'))<a href="{{ route('pay.approval.index') }}" class="fw-semibold small">Lihat semua</a>@endif
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Payroll</th><th>Periode</th><th>Diajukan oleh</th><th class="text-end">Aksi</th></tr></thead>
                    <tbody>
                    @forelse ($menunggu as $r)
                        <tr>
                            <td class="fw-semibold">{{ $r->title }}</td>
                            <td class="text-nowrap">{{ tgl($r->period_start, 'j M') }} – {{ tgl($r->period_end, 'j M Y') }}</td>
                            <td>{{ $r->creator?->namaTampil() ?? '-' }}</td>
                            <td class="text-end"><a href="{{ route('pay.approval.show', $r->id) }}" class="btn btn-light btn-icon" title="Review"><i data-lucide="eye"></i></a></td>
                        </tr>
                    @empty
                        <tr><td colspan="4"><x-empty ikon="clipboard-check" judul="Semua sudah beres" pesan="Tidak ada payroll yang menunggu approval kamu." /></td></tr>
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
    <div class="card-header">Lembur Tim Periode Ini<span class="sub">{{ tgl($awal, 'j M') }} – {{ tgl($akhir, 'j M Y') }} · diinput HR, dihitung saat payroll</span></div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Karyawan</th><th>Hari lembur</th><th>Total jam</th></tr></thead>
            <tbody>
            @forelse ($lembur as $l)
                <tr>
                    <td>{!! orang($l->karyawan->nama, $l->karyawan->jabatan) !!}</td>
                    <td>{{ $l->hari }} hari</td>
                    <td>{{ \App\Domain\Payroll\KehadiranKaryawan::angka($l->jam) }} jam</td>
                </tr>
            @empty
                <tr><td colspan="3"><x-empty ikon="clock" judul="Belum ada lembur periode ini" pesan="Lembur tim diinput HR di modul payroll." /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
