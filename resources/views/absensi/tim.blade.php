@extends('layouts.app')
@section('title', 'Absensi Tim')
@section('breadcrumb', 'Absensi Tim')
@section('content')
<x-page-header judul="Absensi Tim" sub="Rekap kehadiran tim yang diinput HR. Lembur diinput di menu Lembur Tim.">
    <a href="{{ route('absensi.index', ['periode' => $periode, 'export' => 1]) }}" class="btn btn-primary"><i data-lucide="file-down"></i> Export</a>
</x-page-header>

@php $n = $baris->count(); $tot = fn ($k) => $baris->sum(fn ($b) => $b->rekap[$k]); $rata = $n ? round($tot('hadir') / max($n * $hk, 1) * 100) : 0; @endphp
<div class="stat-row">
    @foreach ([['Rata-rata kehadiran', $rata . '%'], ['Sakit / izin', $tot('sakit') + $tot('izin') . ' hari'], ['Cuti', $tot('cuti') . ' hari'], ['Alfa', $tot('alfa') . ' hari'], ['Lembur', ($tot('jam_lembur') + 0) . ' jam']] as [$l, $v])
        <div class="card stat-card"><div class="card-body py-3"><div class="label">{{ $l }}</div><div class="angka fs-4 mt-1">{{ $v }}</div></div></div>
    @endforeach
</div>
<div class="mb-3"><x-catatan>Kalau ada data yang salah, hubungi HR. Data hanya bisa diubah HR sebelum payroll di-generate.</x-catatan></div>
<div class="md-grid">
    <div class="card">
        <x-pilih-periode :periode="$periode" />
        <div class="table-responsive">
            <table class="table table-hover">
                <thead><tr><th>NIK</th><th>Karyawan</th><th class="text-center">Hadir</th><th class="text-center">Sakit / Izin</th><th class="text-center">Cuti</th><th class="text-center">Alfa</th><th class="text-center">Lembur</th><th style="width:170px">Kehadiran <span class="text-muted fw-normal text-lowercase">(s/d hari ini)</span></th><th>Aksi</th></tr></thead>
                <tbody>
                @forelse ($baris as $b)
                    @php $r = $b->rekap; $pct = $hk ? min(100, round($r['hadir'] / $hk * 100)) : 0; @endphp
                    <tr>
                        <td class="kode">{{ $b->karyawan->nik }}</td>
                        <td>{!! orang($b->karyawan->nama, $b->karyawan->jabatan) !!}</td>
                        <td class="text-center">{{ $r['hadir'] }} <span class="text-muted small">/ {{ $hk }} hari</span></td>
                        <td class="text-center">{{ $r['sakit'] + $r['izin'] }} <span class="text-muted small">hari</span></td>
                        <td class="text-center">{{ $r['cuti'] }} <span class="text-muted small">hari</span></td>
                        <td class="text-center {{ $r['alfa'] ? 'text-danger fw-semibold' : '' }}">{{ $r['alfa'] }} <span class="text-muted small fw-normal">hari</span></td>
                        <td class="text-center">{{ $r['jam_lembur'] + 0 }} <span class="text-muted small">jam</span></td>
                        <td><div class="d-flex align-items-center gap-2"><div class="bar flex-grow-1"><span style="width:{{ $pct }}%;background:{{ $pct >= 90 ? '#16a34a' : ($pct >= 75 ? '#d97706' : '#dc2626') }}"></span></div><span class="small fw-semibold" style="width:36px">{{ $pct }}%</span></div></td>
                        <td><a href="{{ route('absensi.index', ['periode' => $periode, 'lihat' => $b->karyawan->id]) }}" class="btn btn-light btn-icon" title="Lihat riwayat harian"><i data-lucide="eye"></i></a></td>
                    </tr>
                @empty
                    <tr><td colspan="9"><x-empty ikon="calendar" judul="Belum ada catatan absensi" pesan="Data absensi, cuti, dan lembur diinput oleh HR." /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($pilih)
        <x-panel :judul="$pilih->karyawan->nama" :sub="nama_periode($periode) . ' · ' . $pilih->karyawan->jabatan">
            @forelse ($harianPilih->take(10) as $h)
                <div class="rinci"><span class="k">{{ $h->tanggal->translatedFormat('D, j M') }}</span><span class="v">{!! badge_status($h->status ?? 'lembur') !!}@if ($h->lembur) <span class="small text-secondary">+{{ $h->lembur + 0 }} jam</span>@endif</span></div>
            @empty
                <div class="text-muted small">Belum ada catatan.</div>
            @endforelse
            <div class="tombol"><a href="{{ route('absensi.index', ['periode' => $periode]) }}" class="btn btn-light">Tutup</a></div>
        </x-panel>
    @endif
</div>
@endsection
