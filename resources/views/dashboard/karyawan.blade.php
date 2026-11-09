@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
@if (! $karyawan)
    <x-empty ikon="user-x" judul="Akun belum terhubung ke data karyawan" pesan="Hubungi Admin." />
@else
<x-page-header judul="Dashboard Karyawan" :sub="$karyawan->jabatan . ' · Departemen ' . $karyawan->departemen?->nama" />

@if ($slip)
    @php $plus = $slipDetail->filter(fn ($d) => $d->component->jenis === 'penambah')->sum('nominal'); $min = $slipDetail->filter(fn ($d) => $d->component->jenis === 'pengurang')->sum('nominal'); @endphp
    <div class="hero-gaji mb-4">
        <div class="me-auto">
            <div class="small opacity-75">Gaji bersih {{ $slip->payroll->namaPeriode() }}</div>
            <div class="fs-2 fw-bold">{{ rupiah($slip->thp) }}</div>
            <div class="small opacity-75">Slip terbit {{ tgl($slip->created_at, 'j M Y') }} setelah disetujui Manager HR</div>
        </div>
        <div class="kotak"><div class="small opacity-75">Total penambah</div><div class="fw-semibold">{{ rupiah($plus) }}</div></div>
        <div class="kotak"><div class="small opacity-75">Total pengurang</div><div class="fw-semibold">{{ rupiah($min) }}</div></div>
        <a href="{{ route('slip.download', $slip) }}" class="btn btn-light"><i data-lucide="file-down"></i> Unduh Slip PDF</a>
    </div>
@else
    <div class="card mb-4"><x-empty ikon="receipt" judul="Slip gaji belum tersedia" pesan="Slip terbit setelah payroll disetujui Manager HR." /></div>
@endif

@include('dashboard._stat')

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">Riwayat Cuti & Lembur <a href="{{ route('absensi.index') }}" class="fw-semibold small">Lihat semua</a></div>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Jenis</th><th>Tanggal</th><th>Keterangan</th><th>Sumber</th></tr></thead>
                    <tbody>
                    @forelse ($riwayat as $r)
                        <tr><td class="fw-semibold">{{ $r->jenis }}</td><td>{{ $r->tanggal }}</td><td class="text-secondary">{{ $r->ket ?? '-' }}</td><td><span class="badge badge-soft-neutral">{{ $r->sumber }}</span></td></tr>
                    @empty
                        <tr><td colspan="4"><x-empty ikon="calendar" judul="Belum ada catatan" pesan="Data absensi, cuti, dan lembur diinput oleh HR." /></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">Rincian Kehadiran {{ tgl($akhir, 'F') }}<span class="sub">Menentukan uang makan & transport</span></div>
            <div class="card-body">
                @foreach ([['Hadir', $rekap['hadir'], 'Dapat uang makan + transport'], ['Sakit', $rekap['sakit'], 'Tidak memotong gaji'], ['Cuti / Izin', $rekap['cuti'] + $rekap['izin'], 'Cuti mengurangi jatah tahunan'], ['Alfa', $rekap['alfa'], 'Tanpa tunjangan, potong gaji pokok']] as [$l, $n, $ket])
                    <div class="d-flex justify-content-between mb-3"><div><div class="fw-semibold">{{ $l }}</div><div class="small text-muted">{{ $ket }}</div></div><div class="fw-semibold">{{ $n }} hari</div></div>
                @endforeach
            </div>
        </div>
    </div>
</div>
@endif
@endsection
