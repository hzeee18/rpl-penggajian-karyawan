@extends('layouts.app')
@section('title', 'Detail karyawan')
@section('breadcrumb', 'Karyawan / Detail')
@section('content')
<x-page-header :judul="'Detail karyawan'" />
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <span class="avatar" style="width:52px;height:52px;font-size:1.3rem">{{ strtoupper(substr($karyawan->nama, 0, 1)) }}</span>
                    <div><div class="h5 mb-0">{{ $karyawan->nama }}</div><div class="text-muted">{{ $karyawan->jabatan }}</div></div>
                </div>
                <dl class="info">
                    <dt>NIK</dt><dd>{{ $karyawan->nik }}</dd>
                    <dt>Departemen</dt><dd>{{ $karyawan->departemen?->nama }}</dd>
                    <dt>Tanggal masuk</dt><dd>{{ tgl($karyawan->tgl_masuk) }} ({{ $karyawan->hitungMasaKerja() }} bulan)</dd>
                    <dt>Status</dt><dd>{{ ucfirst($karyawan->status_kepegawaian) }} · {!! badge_status($karyawan->is_active ? 'aktif' : 'nonaktif') !!}</dd>
                    <dt>Status PTKP</dt><dd>{{ $karyawan->pajak?->status_ptkp ?? '-' }}</dd>
                    <dt>Sisa cuti</dt><dd>{{ $karyawan->sisa_cuti }} hari</dd>
                    <dt>Kontak</dt><dd>{{ $karyawan->email ?? '-' }}<br>{{ $karyawan->telepon }}</dd>
                    <dt>Alamat</dt><dd>{{ $karyawan->alamat ?? '-' }}</dd>
                    <dt>Rekening</dt><dd>{{ $karyawan->nama_bank }} {{ $karyawan->no_rekening }}</dd>
@if ($payrollBaru)
                        <dt>Jabatan payroll</dt><dd>{{ $karyawan->position ? $karyawan->position->code . ' · ' . $karyawan->position->name : '-' }}</dd>
                        <dt>NPWP</dt><dd>{{ $karyawan->has_npwp ? 'Punya' : 'Tidak punya' }}{{ $karyawan->npwp ? ' · tersimpan (terenkripsi)' : '' }}</dd>
                        <dt>Tanggal resign</dt><dd>{{ $karyawan->resign_date ? tgl($karyawan->resign_date) : '-' }}</dd>
@endif
                    <dt>Akun login</dt><dd>{{ $karyawan->user ? $karyawan->user->username . ' (' . $karyawan->user->role->nama . ')' : 'Belum ada' }}</dd>
                </dl>
                @if (auth()->user()->bisa('karyawan', 'update'))<a href="{{ route('karyawan.edit', $karyawan) }}" class="btn btn-outline-primary btn-sm"><i data-lucide="pencil"></i> Ubah data</a>@endif
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                Riwayat kontrak
                @if (auth()->user()->bisa('pay-kontrak'))<a href="{{ route('pay.kontrak.index', ['q' => $karyawan->nik]) }}" class="btn btn-sm btn-primary"><i data-lucide="file-signature"></i> Kelola di Kontrak Payroll</a>@endif
            </div>
            @include('payroll._kontrak-karyawan', ['kontrak' => $karyawan->kontrakPayroll, 'lihatGaji' => auth()->user()->bolehLihatGaji()])
        </div>
    </div>
</div>
@endsection
