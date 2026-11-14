@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
@php $u = auth()->user(); @endphp
<x-page-header judul="Dashboard HR" :sub="'Periode ' . nama_periode($periode) . ' · ' . tgl($awal, 'j M') . ' – ' . tgl($akhir, 'j M Y')">
    @if ($u->bisa('pay-import'))<a href="{{ route('pay.import.index') }}" class="btn btn-light"><i data-lucide="upload"></i> Import CSV</a>@endif
    @if ($u->bisa('pay-run'))<a href="{{ route('pay.run.index') }}" class="btn btn-primary"><i data-lucide="play"></i> Generate Payroll</a>@endif
</x-page-header>

@include('dashboard._stat')

@php
    $langkah = [
        ['Data absensi, leave & lembur dicek', 'Input manual atau Import CSV periode ini'],
        ['Pratinjau & generate payroll', $run ? $run->title . ' · ' . ($run->generated_at ? 'di-generate ' . tgl($run->generated_at, 'j M Y') : 'belum di-generate') : 'Belum ada payroll'],
        ['Approval Manager', match ($run?->status) { 'pending_approval' => 'Menunggu persetujuan ' . ($run->approver?->namaTampil() ?? 'approver'), 'approved' => 'Disetujui', 'rejected' => 'Ditolak: ' . ($run->reject_reason ?? '-'), default => 'Belum diajukan' }],
        ['Slip gaji PDF terbit otomatis', $run?->status === 'approved' ? 'Sudah tersedia di akun setiap karyawan' : 'Terbit setelah disetujui'],
    ];
@endphp
<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">Progres Payroll {{ $run?->title }}<span class="sub">Approval oleh approver HR yang ditetapkan</span></div>
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
                @if ($run && $u->bisa('pay-run'))<a href="{{ route('pay.run.show', $run->id) }}" class="btn btn-light btn-sm mt-4">Lihat payroll</a>@endif
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">Input Leave & Lembur Terbaru
                @if ($u->bisa('pay-leave'))<a href="{{ route('pay.leave.index') }}" class="fw-semibold small">Lihat semua</a>@endif
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Karyawan</th><th>Jenis</th><th>Tanggal</th><th>Sumber</th></tr></thead>
                    <tbody>
                    @forelse ($terbaru as $t)
                        <tr>
                            <td>{!! orang($t->karyawan?->nama ?? '-', $t->karyawan?->departemen?->nama) !!}</td>
                            <td>{{ $t->jenis }}</td>
                            <td class="text-nowrap">{{ $t->tanggal }}</td>
                            <td><span class="badge badge-soft-neutral">{{ $t->sumber }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="4"><x-empty ikon="calendar-x" judul="Belum ada input" pesan="Leave dan lembur yang diinput atau diimport akan muncul di sini." /></td></tr>
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
            <div class="card-header">Kehadiran per Departemen<span class="sub">Jumlah hari hadir periode berjalan</span></div>
            <div class="card-body">
                @forelse ($perDept as $d)
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1"><span>{{ $d->nama }}</span><span class="small text-secondary">{{ $d->hadir }} hari · {{ $d->karyawan }} karyawan</span></div>
                        <div class="bar"><span style="width:{{ round($d->hadir / $maks * 100) }}%"></span></div>
                    </div>
                @empty
                    <x-empty ikon="building-2" judul="Belum ada departemen" pesan="Departemen dikelola Super Admin." />
                @endforelse
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
