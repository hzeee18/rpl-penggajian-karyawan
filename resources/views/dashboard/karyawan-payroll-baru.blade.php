@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
@if (! $karyawan)
    <x-empty ikon="user-x" judul="Akun belum terhubung ke data karyawan" pesan="Hubungi Admin." />
@else
<x-page-header judul="Dashboard Karyawan" :sub="$karyawan->jabatan . ' · Departemen ' . $karyawan->departemen?->nama" />

@include('dashboard._stat')

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">Riwayat Leave & Lembur
                @if (auth()->user()->bisa('absensi'))<a href="{{ route('absensi.index') }}" class="fw-semibold small">Lihat semua</a>@endif
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Jenis</th><th>Tanggal</th><th>Keterangan</th></tr></thead>
                    <tbody>
                    @forelse ($riwayat as $r)
                        <tr><td class="fw-semibold">{{ $r->jenis }}</td><td>{{ $r->tanggal }}</td><td class="text-secondary">{{ $r->ket ?? '-' }}</td></tr>
                    @empty
                        <tr><td colspan="3"><x-empty ikon="calendar" judul="Belum ada catatan" pesan="Data absensi, leave, dan lembur diinput oleh HR." /></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">Rincian Kehadiran<span class="sub">Periode {{ tgl($awal, 'j M') }} – {{ tgl($akhir, 'j M Y') }}</span></div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-3"><div class="fw-semibold">Hadir</div><div class="fw-semibold">{{ $rekap['hadir'] }} hari</div></div>
                <div class="d-flex justify-content-between mb-3"><div class="fw-semibold">Terlambat</div><div class="fw-semibold">{{ $rekap['terlambat'] }} kali</div></div>
                @foreach ($rekap['leave_per_tipe'] as $tipe => $n)
                    <div class="d-flex justify-content-between mb-3"><div class="fw-semibold">{{ $tipe }}</div><div class="fw-semibold">{{ $n }} hari</div></div>
                @endforeach
                <div class="d-flex justify-content-between mb-3"><div class="fw-semibold">Lembur</div><div class="fw-semibold">{{ \App\Domain\Payroll\KehadiranKaryawan::angka($rekap['jam_lembur']) }} jam</div></div>
                <div class="small text-muted">Potongan dan tunjangan dihitung oleh HR saat payroll sesuai aturan perusahaan.</div>
            </div>
        </div>
    </div>
</div>
@endif
@endsection
