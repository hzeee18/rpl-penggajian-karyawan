@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
<x-page-header judul="Dashboard HR" :sub="'Periode ' . nama_periode($periode) . ' · ' . tgl($awal, 'j M') . ' – ' . tgl($akhir, 'j M Y')">
    <a href="{{ route('import.index', ['jenis' => 'absensi']) }}" class="btn btn-light"><i data-lucide="upload"></i> Import Absensi</a>
    <a href="{{ route('payroll.rekap') }}" class="btn btn-primary"><i data-lucide="play"></i> Proses Payroll</a>
</x-page-header>

@include('dashboard._stat')

@php
    $s = $payroll?->status;
    $langkah = [
        ['Data absensi & lembur dicek', $payroll ? 'Rekap periode ' . $payroll->namaPeriode() : 'Cek rekap sebelum generate'],
        ['Generate payroll', $payroll ? tgl($payroll->tgl_generate, 'j M Y') . ' · ' . $payroll->details()->distinct('karyawan_id')->count('karyawan_id') . ' karyawan dihitung' : 'Belum di-generate'],
        ['Approval Manager HR', ['menunggu' => 'Menunggu persetujuan Manager HR', 'final' => 'Disetujui', 'ditolak' => 'Ditolak, perlu revisi'][$s] ?? 'Belum diajukan'],
        ['Slip gaji PDF terbit otomatis', $s === 'final' ? 'Sudah dikirim ke akun setiap karyawan' : 'Dikirim ke akun setiap karyawan'],
    ];
    $tahap = ['draft' => 2, 'ditolak' => 2, 'menunggu' => 3, 'final' => 5][$s] ?? 1;
@endphp
<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">Progres Payroll {{ $payroll ? $payroll->namaPeriode() : '' }}<span class="sub">Approval payroll cukup oleh Manager HR</span></div>
            <div class="card-body">
                <div class="stepper">
                    @foreach ($langkah as $i => [$judul, $ket])
                        @php $n = $i + 1; $kelas = $n < $tahap ? 'selesai' : ($n === $tahap ? 'aktif' : 'belum'); @endphp
                        <div class="step {{ $kelas }}">
                            <span class="bulat">{{ $n }}</span>
                            <div><div class="judul">{{ $judul }}</div><div class="ket">{{ $ket }}</div></div>
                        </div>
                    @endforeach
                </div>
                @if ($payroll)<a href="{{ route('payroll.show', $payroll) }}" class="btn btn-light btn-sm mt-4">Lihat payroll</a>@endif
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">Input Cuti & Lembur Terbaru <a href="{{ route('cuti.index') }}" class="fw-semibold small">Lihat semua</a></div>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Karyawan</th><th>Jenis</th><th>Tanggal</th><th>Diinput oleh</th></tr></thead>
                    <tbody>
                    @forelse ($terbaru as $t)
                        <tr>
                            <td>{!! orang($t->karyawan->nama, $t->karyawan->departemen?->nama) !!}</td>
                            <td>{{ $t->jenis }}</td>
                            <td class="text-nowrap">{{ $t->tanggal }}</td>
                            <td><div class="fw-medium">{{ $t->oleh }}</div><div class="small text-muted">{{ $t->peran }}</div></td>
                        </tr>
                    @empty
                        <tr><td colspan="4"><x-empty ikon="calendar-x" judul="Belum ada input" pesan="Cuti dan lembur yang diinput akan muncul di sini." /></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">Kehadiran per Departemen<span class="sub">Periode berjalan</span></div>
            <div class="card-body">
                @foreach ($perDept as $d)
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1"><span>{{ $d->nama }}</span><span class="small text-secondary">{{ $d->persen }}%</span></div>
                        <div class="bar"><span style="width:{{ $d->persen }}%"></span></div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">Pengingat</div>
            <div class="card-body">
                @forelse ($pengingat as [$judul, $ket])
                    <div class="mb-3"><div class="fw-semibold">{{ $judul }}</div><div class="small text-muted">{{ $ket }}</div></div>
                @empty
                    <x-empty ikon="check-check" judul="Semua sudah beres" pesan="Tidak ada yang perlu ditindaklanjuti." />
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
