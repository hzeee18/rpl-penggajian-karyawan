@extends('layouts.app')
@section('title', 'Absensi, Cuti & Lembur')
@section('breadcrumb', 'Absensi, Cuti & Lembur')
@section('content')
<x-page-header judul="Absensi, Cuti & Lembur" sub="Riwayat kehadiran, cuti & lembur kamu. Semua data diinput HR, kamu cukup melihat.">
    <form method="get" class="position-relative">
        <label class="btn btn-light mb-0"><i data-lucide="calendar"></i> {{ nama_periode($periode) }}
            <input type="month" name="periode" value="{{ $periode }}" onchange="this.form.submit()" onclick="try{this.showPicker()}catch(e){}" style="position:absolute;inset:0;opacity:0;cursor:pointer"></label>
    </form>
</x-page-header>

<div class="stat-row">
    @foreach ([['Hadir', $rekap['hadir'] . '/' . $hk . ' hari'], ['Sakit / izin', $rekap['sakit'] + $rekap['izin'] . ' hari'], ['Cuti', $rekap['cuti'] . ' hari'], ['Alfa', $rekap['alfa'] . ' hari'], ['Lembur', ($rekap['jam_lembur'] + 0) . ' jam'], ['Sisa cuti tahunan', $karyawan->sisa_cuti . ' hari']] as [$l, $v])
        <div class="card stat-card"><div class="card-body py-3"><div class="label">{{ $l }}</div><div class="angka fs-4 mt-1">{{ $v }}</div></div></div>
    @endforeach
</div>
<div class="mb-3 d-flex align-items-center gap-3"><x-catatan>Data diinput HR. Kalau ada yang salah, hubungi HR sebelum payroll di-generate.</x-catatan><a href="{{ route('slip.index') }}" class="fw-semibold text-nowrap">Lihat slip gaji <i data-lucide="chevron-right"></i></a></div>
<div class="md-grid">
    <div class="card">
        <div class="card-search d-flex align-items-center gap-2">
            <div class="chips">
                @foreach (['' => 'Semua', 'hadir' => 'Hadir', 'sakit' => 'Sakit / Izin', 'cuti' => 'Cuti', 'lembur' => 'Lembur'] as $v => $l)
                    <a href="{{ route('absensi.index', array_filter(['periode' => $periode, 'filter' => $v])) }}" class="chip {{ (string) request('filter') === $v ? 'active' : '' }}">{{ $l }}</a>
                @endforeach
            </div>
            <span class="small text-muted ms-auto">{{ $jumlah }} catatan · {{ nama_periode($periode) }}</span>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Tanggal</th><th>Status</th><th>Keterangan</th><th class="text-end">Lembur</th></tr></thead>
                <tbody>
                @forelse ($baris as $b)
                    <tr>
                        <td class="fw-semibold text-nowrap">{{ $b->tanggal->translatedFormat('j M') }}</td>
                        <td>{!! $b->status ? badge_status($b->status) : '<span class="badge badge-soft-primary">Lembur</span>' !!}</td>
                        <td>{!! orang($b->keterangan, 'Diinput ' . $b->oleh, 'avatar-sm', $b->oleh === 'HR' ? 'H R' : 'M G') !!}</td>
                        <td class="text-end">{{ $b->lembur ? ($b->lembur + 0) . ' jam' : '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4"><x-empty ikon="calendar" judul="Belum ada catatan absensi" pesan="Data absensi, cuti, dan lembur diinput oleh HR." /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
