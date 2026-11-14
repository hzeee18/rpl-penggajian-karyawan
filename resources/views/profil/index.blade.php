@extends('layouts.app')
@section('title', 'Profil & Kontrak')
@section('breadcrumb', 'Profil & Kontrak')
@section('content')
@if (! $karyawan)
    <x-empty ikon="user-x" judul="Akun belum terhubung ke data karyawan" pesan="Hubungi Admin." />
@else
<x-page-header judul="Profil & Kontrak" sub="Data diri dan kontrak kerja Anda. Perubahan data dilakukan oleh HR." />

<div class="profil-head">
    <span class="avatar" style="width:56px;height:56px;font-size:18px">{{ inisial($karyawan->nama) }}</span>
    <div class="me-auto">
        <div class="fs-5 fw-bold">{{ $karyawan->nama }}</div>
        <div class="text-secondary">{{ $karyawan->jabatan }} · Departemen {{ $karyawan->departemen?->nama }}</div>
        <div class="small text-muted">NIK {{ $karyawan->nik }} · Bergabung {{ tgl($karyawan->tgl_masuk, 'F Y') }}</div>
    </div>
    <span class="badge {{ $karyawan->is_active ? 'badge-soft-success' : 'badge-soft-neutral' }}">{{ $karyawan->is_active ? 'Karyawan aktif' : 'Nonaktif' }}</span>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">Data Pribadi</div>
            <div class="card-body">
                @foreach ([['NIK', $karyawan->nik], ['Nama lengkap', $karyawan->nama], ['Email', $karyawan->email ?? '-'], ['No. HP', $karyawan->telepon ?? '-'],
                    ['Status PTKP', ($karyawan->pajak?->status_ptkp ?? '-') . (isset(\App\Http\Controllers\ProfilController::PTKP[$karyawan->pajak?->status_ptkp]) ? ' (' . \App\Http\Controllers\ProfilController::PTKP[$karyawan->pajak->status_ptkp] . ')' : '')],
                    ['Mulai kerja', tgl($karyawan->tgl_masuk)]] as [$k, $v])
                    <div class="d-flex py-2 border-bottom"><span class="text-muted" style="width:130px">{{ $k }}</span><span class="fw-medium">{{ $v }}</span></div>
                @endforeach
            </div>
        </div>
    </div>
    @include('profil._kontrak-payroll')
</div>

<div class="card">
    <div class="card-header">Riwayat Kontrak</div>
    @include('payroll._kontrak-karyawan', ['kontrak' => $karyawan->kontrakPayroll, 'lihatGaji' => true])
</div>
@endif
@endsection
