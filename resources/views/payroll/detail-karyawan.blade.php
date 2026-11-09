@extends('layouts.app')
@section('title', 'Rincian gaji · ' . $karyawan->nama)
@section('breadcrumb', 'Payroll / Rincian karyawan')
@section('content')
<x-page-header :judul="$karyawan->nama" :sub="'Rincian gaji payroll ' . $payroll->namaPeriode()">
    <a href="{{ route('payroll.show', $payroll) }}" class="btn btn-light"><i data-lucide="arrow-left"></i> Kembali ke payroll</a>
</x-page-header>
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card"><div class="card-body">
            <div class="h6 mb-0">{{ $karyawan->nama }}</div>
            <div class="small text-muted mb-3">{{ $karyawan->nik }} · {{ $karyawan->jabatan }} · PTKP {{ $karyawan->pajak?->status_ptkp }} (TER {{ $karyawan->pajak?->golongan?->kode }})</div>
            <div class="small text-muted">Rekap {{ tgl($awal, 'j M') }} – {{ tgl($akhir, 'j M Y') }}</div>
            <table class="table table-sm mb-0">
                @foreach (['hadir' => 'Hadir', 'terlambat' => 'Terlambat', 'alfa' => 'Alfa', 'cuti' => 'Cuti', 'izin' => 'Izin', 'sakit' => 'Sakit'] as $k => $l)
                    <tr><td>{{ $l }}</td><td class="text-end">{{ $rekap[$k] }} hari</td></tr>
                @endforeach
                <tr><td>Lembur</td><td class="text-end">{{ $rekap['jam_lembur'] + 0 }} jam</td></tr>
            </table>
        </div></div>
    </div>
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">Komponen gaji</div>
            @include('slip._rincian', ['details' => $r->rows])
        </div>
    </div>
</div>
@endsection
