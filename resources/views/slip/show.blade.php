@extends('layouts.app')
@section('title', 'Slip gaji ' . $slip->payroll->namaPeriode())
@section('breadcrumb', 'Slip Gaji / Detail')
@section('content')
<x-page-header :judul="'Slip Gaji ' . $slip->payroll->namaPeriode()" :sub="$slip->karyawan->nama . ' · ' . $slip->karyawan->jabatan">
    <a href="{{ route('slip.index') }}" class="btn btn-light"><i data-lucide="arrow-left"></i> Kembali</a>
    <a href="{{ route('slip.download', $slip) }}" class="btn btn-primary"><i data-lucide="file-down"></i> Unduh PDF</a>
</x-page-header>
<div class="card" style="max-width:900px">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>
            <div class="fw-bold">{{ $slip->karyawan->nama }}</div>
            <div class="small text-muted fw-normal">{{ $slip->karyawan->nik }} · {{ $slip->karyawan->jabatan }} · {{ $slip->karyawan->departemen?->nama }}</div>
        </div>
        
    </div>
    @include('slip._rincian')
    <div class="card-footer bg-white small text-muted">Rekening: {{ $slip->karyawan->nama_bank }} {{ $slip->karyawan->no_rekening }} · Periode {{ $slip->payroll->namaPeriode() }}</div>
</div>
@endsection
