@extends('layouts.app')
@section('title', 'Proses Payroll')
@section('breadcrumb', 'Payroll / Proses Payroll')
@section('content')
@php $u = auth()->user(); @endphp

@if ($keadaan !== 'siap')
    {{-- Tidak ada periode yang bisa diproses sekarang --}}
    <x-page-header judul="Proses Payroll" sub="Payroll diproses per periode dari menu Periode. Satu periode hanya bisa di-generate sekali.">
        @if ($u->bisa('master-payroll', 'create'))<a href="{{ route('master-payroll.index', ['baru' => 1]) }}" class="btn btn-primary"><i data-lucide="plus"></i> Buat Periode Baru</a>@endif
    </x-page-header>
    @foreach ($menunggu as $m)
        <div class="mb-3"><x-catatan tipe="info">Payroll {{ $m->namaPeriode() }} {{ $u->isManagerHr() ? 'menunggu persetujuan kamu' : 'sedang menunggu approval Manager HR' }}. <a href="{{ route('payroll.show', $m) }}" class="fw-semibold">Lihat</a></x-catatan></div>
    @endforeach
    <div class="card">
        @if ($keadaan === 'belum_masa')
            @php $sisa = (int) now()->startOfDay()->diffInDays($master->tgl_mulai, false); @endphp
            <x-empty ikon="calendar-clock" :judul="'Periode ' . nama_periode($master->periode) . ' belum dimulai'"
                     :pesan="'Periode ' . tgl($master->tgl_mulai, 'j M') . ' – ' . tgl($master->tgl_selesai, 'j M Y') . ' (' . $master->hari_kerja . ' hari kerja). Tombol generate aktif mulai ' . tgl($master->tgl_mulai) . ', ' . $sisa . ' hari lagi.'">
                <button class="btn btn-primary" disabled><i data-lucide="play"></i> Generate Payroll {{ nama_periode($master->periode) }}</button>
                <a href="{{ route('master-payroll.index', ['ubah' => $master->id]) }}" class="btn btn-light ms-2">Atur Periode</a>
            </x-empty>
        @else
            <x-empty ikon="calendar-check" judul="Semua periode sudah diproses"
                     :pesan="($terakhir ? 'Payroll terakhir: ' . $terakhir->namaPeriode() . '. ' : '') . 'Buat periode baru di menu Periode untuk memproses gaji berikutnya.'">
                @if ($u->bisa('master-payroll', 'create'))<a href="{{ route('master-payroll.index', ['baru' => 1]) }}" class="btn btn-primary"><i data-lucide="plus"></i> Buat Periode Baru</a>@endif
                <a href="{{ route('laporan.index') }}" class="btn btn-light ms-2">Riwayat Payroll</a>
            </x-empty>
        @endif
    </div>
@else
    @php
        $bisa = $semua->isNotEmpty() && (! $payroll || $payroll->bisaDigenerate());
        $kondisiOpsi = ['alfa' => 'Ada alfa', 'lembur' => 'Ada lembur', 'belum_lengkap' => 'Absensi belum lengkap'];
        $tanpa = function (string $kunci, $nilai) { $q = request()->except(['page', 'ubah', 'baru']); $q[$kunci] = array_values(array_diff((array) ($q[$kunci] ?? []), [(string) $nilai])); return route('payroll.index', $q); };
        $adaFilter = $fKaryawan || $fDepartemen || $fKondisi;
    @endphp
    <x-page-header :judul="'Proses Payroll ' . nama_periode($periode)" :sub="'Periode ' . tgl($awal, 'j M') . ' – ' . tgl($akhir, 'j M Y') . ' · ' . $master->hari_kerja . ' hari kerja. Cek rekap dulu, perbaiki yang salah, lalu generate.'">
        <a href="{{ route('laporan.index') }}" class="btn btn-light"><i data-lucide="history"></i> Riwayat</a>
        <button class="btn btn-primary" data-drawer="#drawer" @disabled(! $bisa)><i data-lucide="play"></i> Generate Payroll {{ nama_periode($periode) }}</button>
    </x-page-header>
    @foreach ($menunggu as $m)
        <div class="mb-3"><x-catatan tipe="info">Payroll {{ $m->namaPeriode() }} {{ $u->isManagerHr() ? 'menunggu persetujuan kamu' : 'sedang menunggu approval Manager HR' }}. <a href="{{ route('payroll.show', $m) }}" class="fw-semibold">Lihat</a></x-catatan></div>
    @endforeach
    @if ($payroll?->status === 'ditolak' && ($tolak = $payroll->approvals()->where('status', 'ditolak')->first()))
        <div class="mb-3"><x-catatan tipe="danger">Ditolak Manager HR: {{ $tolak->catatan }}. Perbaiki data lalu generate ulang.</x-catatan></div>
    @endif

    <div class="stat-row">
        @foreach ([['Karyawan dihitung', $semua->count(), 'orang'], ['Total gaji pokok', jt($estimasi['gp']), 'estimasi'], ['Total lembur', jt($estimasi['lembur']), 'estimasi'], ['PPh21 (TER)', jt($estimasi['pph']), 'estimasi']] as [$l, $v, $s])
            <div class="card stat-card"><div class="card-body py-3"><div class="label">{{ $l }}</div><div class="angka fs-4 mt-1">{{ $v }} <span class="fs-6 fw-normal text-muted">{{ $s }}</span></div></div></div>
        @endforeach
    </div>

    <div class="card">
        <form class="card-search" method="get" data-auto-submit>
            <div class="filter-bar filter-bar-3">
                <div><label class="form-label">Karyawan</label>
                    <select name="karyawan_id[]" multiple class="form-select" data-placeholder="Semua karyawan" data-satuan="karyawan">@foreach ($semua as $b)<option value="{{ $b['karyawan']->id }}" @selected(in_array($b['karyawan']->id, $fKaryawan))>{{ $b['karyawan']->nama }} · {{ $b['karyawan']->nik }}</option>@endforeach</select></div>
                <div><label class="form-label">Departemen</label>
                    <select name="departemen_id[]" multiple class="form-select" data-placeholder="Semua departemen" data-satuan="departemen">@foreach ($departemen as $d)<option value="{{ $d->id }}" @selected(in_array($d->id, $fDepartemen))>{{ $d->nama }}</option>@endforeach</select></div>
                <div><label class="form-label">Kondisi</label>
                    <select name="kondisi[]" multiple class="form-select" data-placeholder="Semua kondisi" data-satuan="kondisi">@foreach ($kondisiOpsi as $v => $l)<option value="{{ $v }}" @selected(in_array($v, $fKondisi))>{{ $l }}</option>@endforeach</select></div>
            </div>
            <div class="filter-aktif">
                <span class="ringkas"><b class="text-body">{{ $baris->total() }}</b> dari {{ $semua->count() }} karyawan · {{ $hkBerjalan }} hari kerja sudah berjalan</span>
                @foreach ($semua->pluck('karyawan')->whereIn('id', $fKaryawan) as $k)<a href="{{ $tanpa('karyawan_id', $k->id) }}" class="chip-filter"><span class="label">Karyawan</span> {{ $k->nama }}<span class="x"><i data-lucide="x"></i></span></a>@endforeach
                @foreach ($departemen->whereIn('id', $fDepartemen) as $d)<a href="{{ $tanpa('departemen_id', $d->id) }}" class="chip-filter"><span class="label">Dept</span> {{ $d->nama }}<span class="x"><i data-lucide="x"></i></span></a>@endforeach
                @foreach ($fKondisi as $k)<a href="{{ $tanpa('kondisi', $k) }}" class="chip-filter"><span class="label">Kondisi</span> {{ $kondisiOpsi[$k] }}<span class="x"><i data-lucide="x"></i></span></a>@endforeach
                @if ($adaFilter)<a href="{{ route('payroll.index') }}" class="reset">Reset semua</a>@endif
            </div>
        </form>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead><tr><th>Karyawan</th><th class="text-center">Hadir</th><th class="text-center">Sakit/Izin/Cuti</th><th class="text-center">Alfa</th><th class="text-end">Lembur</th><th>Aksi</th></tr></thead>
                <tbody>
                @forelse ($baris as $b)
                    @php $r = $b['rekap']; $tercatat = $r['hadir'] + $r['sakit'] + $r['izin'] + $r['cuti'] + $r['alfa']; @endphp
                    <tr>
                        <td>{!! orang($b['karyawan']->nama, $b['karyawan']->nik . ' · ' . ($b['karyawan']->departemen?->nama ?? '-')) !!}</td>
                        <td class="text-center">{{ $r['hadir'] }}@if ($tercatat < $hkBerjalan)<div class="small text-warning-emphasis">{{ $hkBerjalan - $tercatat }} hari belum tercatat</div>@endif</td>
                        <td class="text-center">{{ $r['sakit'] + $r['izin'] + $r['cuti'] }}</td>
                        <td class="text-center {{ $r['alfa'] ? 'text-danger fw-semibold' : '' }}">{{ $r['alfa'] }}</td>
                        <td class="text-end">{{ $r['jam_lembur'] ? ($r['jam_lembur'] + 0) . ' jam' : '-' }}</td>
                        <td><a href="{{ route('absensi.index', ['karyawan_id' => [$b['karyawan']->id], 'dari' => $awal->toDateString(), 'sampai' => $akhir->toDateString()]) }}" class="btn btn-light btn-icon" title="Lihat & perbaiki data kehadiran"><i data-lucide="pencil"></i></a></td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-empty ikon="search-x" judul="Tidak ada karyawan yang cocok" pesan="Coba ubah atau reset filter." /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($baris->lastPage() > 1) @include('partials.halaman', ['data' => $baris, 'label' => 'karyawan']) @endif
    </div>

    <x-panel :buka="$errors->any()" :judul="'Generate Payroll ' . nama_periode($periode)" :sub="tgl($awal, 'j M') . '–' . tgl($akhir, 'j M Y') . ' · ' . $master->hari_kerja . ' hari kerja'">
        <div class="rinci"><span class="k">Karyawan dihitung</span><span class="v">{{ $semua->count() }}</span></div>
        <div class="rinci"><span class="k">Total gaji pokok</span><span class="v">{{ rupiah($estimasi['gp']) }}</span></div>
        <div class="rinci"><span class="k">Total lembur</span><span class="v">{{ rupiah($estimasi['lembur']) }}</span></div>
        <div class="rinci"><span class="k">PPh21 (TER)</span><span class="v">{{ rupiah($estimasi['pph']) }}</span></div>
        <div class="rinci mb-3"><span class="k fw-semibold">Estimasi gaji bersih</span><span class="v">{{ rupiah($estimasi['bersih']) }}</span></div>
        <x-catatan>{{ $u->isManagerHr() ? 'Karena kamu Manager HR, payroll yang kamu generate langsung disetujui dan slip gaji langsung terbit.' : 'Setelah generate, payroll dikirim ke Manager HR untuk disetujui.' }} Periode ini tidak bisa di-generate lagi setelahnya.</x-catatan>
        <form id="formGenerate" method="post" action="{{ route('payroll.generate') }}" data-confirm="{{ $semua->count() }} karyawan akan dihitung. Setelah generate, data absensi, cuti, dan lembur periode ini terkunci, lalu payroll {{ $u->isManagerHr() ? 'langsung disetujui dan slip gaji terbit' : 'dikirim ke Manager HR' }}." data-confirm-judul="Generate payroll {{ nama_periode($periode) }}?" data-confirm-ok="Ya, Generate">
            @csrf <input type="hidden" name="periode" value="{{ $periode }}">
        </form>
        <div class="tombol">
            <button type="button" class="btn btn-light" data-tutup-drawer>Batal</button>
            @if ($bisa)<button form="formGenerate" class="btn btn-primary">Generate Payroll</button>@endif
        </div>
    </x-panel>
@endif
@endsection
