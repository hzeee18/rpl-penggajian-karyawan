@extends('layouts.app')
@section('title', 'Absensi, Leave & Lembur')
@section('breadcrumb', 'Absensi, Leave & Lembur')
@section('content')
<x-page-header judul="Absensi, Leave & Lembur" :sub="'Riwayat kehadiran kamu periode ' . tgl($awal, 'j M') . ' – ' . tgl($akhir, 'j M Y') . '. Semua data diinput HR, kamu cukup melihat.'">
    <form method="get" class="position-relative">
        <label class="btn btn-light mb-0"><i data-lucide="calendar"></i> {{ nama_periode($periode) }}
            <input type="month" name="periode" value="{{ $periode }}" onchange="this.form.submit()" onclick="try{this.showPicker()}catch(e){}" style="position:absolute;inset:0;opacity:0;cursor:pointer"></label>
    </form>
</x-page-header>

<div class="stat-row">
    @foreach ([['Hadir', $rekap['hadir'] . ' hari'], ['Terlambat', $rekap['terlambat'] . ' kali'], ['Leave', $rekap['leave'] . ' hari'], ['Lembur', \App\Domain\Payroll\KehadiranKaryawan::angka($rekap['jam_lembur']) . ' jam']] as [$l, $v])
        <div class="card stat-card"><div class="card-body py-3"><div class="label">{{ $l }}</div><div class="angka fs-4 mt-1">{{ $v }}</div></div></div>
    @endforeach
</div>
<div class="mb-3 d-flex align-items-center gap-3"><x-catatan>Data diinput HR. Kalau ada yang salah, hubungi HR sebelum payroll di-generate.</x-catatan>
    @if (auth()->user()->bisa('pay-slip'))<a href="{{ route('pay.slip.index') }}" class="fw-semibold text-nowrap">Lihat slip gaji <i data-lucide="chevron-right"></i></a>@endif
</div>
<div class="md-grid">
    <div class="card">
        <div class="card-search d-flex align-items-center gap-2">
            <div class="chips">
                @foreach (['' => 'Semua', 'hadir' => 'Hadir', 'leave' => 'Leave', 'lembur' => 'Lembur'] as $v => $l)
                    <a href="{{ route('absensi.index', array_filter(['periode' => $periode, 'filter' => $v])) }}" class="chip {{ (string) request('filter') === $v ? 'active' : '' }}">{{ $l }}</a>
                @endforeach
            </div>
            <span class="small text-muted ms-auto">{{ $jumlah }} catatan · {{ nama_periode($periode) }}</span>
        </div>
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Tanggal</th><th>Status</th><th>Jam / Keterangan</th><th class="text-end">Lembur</th></tr></thead>
                <tbody>
                @forelse ($baris as $b)
                    <tr>
                        <td class="fw-semibold text-nowrap">{{ $b->tanggal->translatedFormat('D, j M') }}</td>
                        <td>
                            @if ($b->status === 'leave')<span class="badge badge-soft-info">{{ $b->label }}</span>
                            @elseif ($b->status)
                                {!! badge_status($b->status) !!}
                            @else<span class="badge badge-soft-primary">Lembur</span>@endif
                        </td>
                        <td class="text-secondary">{{ $b->keterangan ?? '-' }}</td>
                        <td class="text-end">{{ bccomp($b->lembur, '0', 2) > 0 ? \App\Domain\Payroll\KehadiranKaryawan::angka($b->lembur) . ' jam' : '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4"><x-empty ikon="calendar" judul="Belum ada catatan" pesan="Data absensi, leave, dan lembur diinput oleh HR." /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
