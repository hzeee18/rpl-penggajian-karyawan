@extends('layouts.app')
@section('title', 'Absensi, Cuti & Lembur')
@section('breadcrumb', 'Absensi, Cuti & Lembur')
@section('content')
@php
    $tipeUbah = $ubah?->tipe;
    $m = $ubah?->model;
    $aktif = old('_jenis', $tipeUbah ?? request('jenis_baru', 'absensi'));
    $kembali = route('absensi.index', request()->except(['ubah', 'baru', 'jenis_baru']));
@endphp
<x-page-header judul="Absensi, Cuti & Lembur" sub="Semua data kehadiran dalam satu tabel. Data yang disimpan HR langsung final, tanpa approval.">
    <a href="{{ route('import.index', ['jenis' => 'absensi']) }}" class="btn btn-light"><i data-lucide="upload"></i> Import Excel</a>
    <a href="{{ request()->fullUrlWithQuery(['baru' => 1, 'ubah' => null]) }}" class="btn btn-primary"><i data-lucide="plus"></i> Tambah Data</a>
</x-page-header>

<div class="tab-nav">
    <a href="{{ route('absensi.index') }}" class="active">Semua data</a>
    <a href="{{ route('absensi.index', ['tab' => 'harian']) }}">Input harian</a>
</div>

<div class="stat-row">
    @foreach ([['Hadir', $hitung['hadir'] ?? 0, 'catatan'], ['Sakit', $hitung['sakit'] ?? 0, 'pengajuan'], ['Izin', $hitung['izin'] ?? 0, 'pengajuan'], ['Cuti', $hitung['cuti'] ?? 0, 'pengajuan'], ['Alfa', $hitung['alfa'] ?? 0, 'catatan'], ['Lembur', $hitung['lembur'] ?? 0, 'catatan']] as [$l, $v, $s])
        <div class="card stat-card"><div class="card-body py-3"><div class="label">{{ $l }}</div><div class="angka fs-4 mt-1">{{ $v }} <span class="fs-6 fw-normal text-muted">{{ $s }}</span></div></div></div>
    @endforeach
</div>

<div class="card" data-ajax-wadah>
    {{-- Filter: rentang tanggal + multi pilih karyawan, departemen, jenis. Langsung diterapkan tanpa tombol. --}}
    @php
        $jenisOpsi = ['hadir' => 'Hadir', 'terlambat' => 'Terlambat', 'alfa' => 'Alfa', 'cuti' => 'Cuti', 'izin' => 'Izin', 'sakit' => 'Sakit', 'lembur' => 'Lembur'];
        $tanpa = function (string $kunci, $nilai = null) {
            $q = request()->except(['page', 'ubah', 'baru', 'jenis_baru']);
            if ($nilai === null) { unset($q[$kunci]); if ($kunci === 'dari') unset($q['sampai']); }
            else { $q[$kunci] = array_values(array_diff((array) ($q[$kunci] ?? []), [(string) $nilai])); }
            return route('absensi.index', $q);
        };
        $rentangUbah = request()->filled('dari') && [$dari, $sampai] !== $rentangDefault;
        $adaFilter = $rentangUbah || $fKaryawan || $fDepartemen || $fJenis;
    @endphp
    <form class="card-search" method="get" data-auto-submit>
        <div class="filter-bar">
            <x-rentang label="Rentang tanggal" :nilai-awal="$dari" :nilai-akhir="$sampai" />
            <div><label class="form-label">Karyawan</label>
                <select name="karyawan_id[]" multiple class="form-select" data-placeholder="Semua karyawan" data-satuan="karyawan">@foreach ($karyawan as $k)<option value="{{ $k->id }}" @selected(in_array($k->id, $fKaryawan))>{{ $k->nama }} · {{ $k->nik }}</option>@endforeach</select></div>
            <div><label class="form-label">Departemen</label>
                <select name="departemen_id[]" multiple class="form-select" data-placeholder="Semua departemen" data-satuan="departemen">@foreach ($departemen as $d)<option value="{{ $d->id }}" @selected(in_array($d->id, $fDepartemen))>{{ $d->nama }}</option>@endforeach</select></div>
            <div><label class="form-label">Jenis</label>
                <select name="jenis[]" multiple class="form-select" data-placeholder="Semua jenis" data-satuan="jenis">@foreach ($jenisOpsi as $v => $l)<option value="{{ $v }}" @selected(in_array($v, $fJenis))>{{ $l }}</option>@endforeach</select></div>
        </div>
        <div class="filter-aktif">
            <span class="ringkas"><b class="text-body">{{ $total }}</b> catatan · {{ tgl($dari, 'j M Y') }} – {{ tgl($sampai, 'j M Y') }}</span>
            @if ($rentangUbah)<a href="{{ $tanpa('dari') }}" class="chip-filter"><span class="label">Tanggal</span> {{ tgl($dari, 'j M') }} – {{ tgl($sampai, 'j M') }}<span class="x"><i data-lucide="x"></i></span></a>@endif
            @foreach ($karyawan->whereIn('id', $fKaryawan) as $k)<a href="{{ $tanpa('karyawan_id', $k->id) }}" class="chip-filter"><span class="label">Karyawan</span> {{ $k->nama }}<span class="x"><i data-lucide="x"></i></span></a>@endforeach
            @foreach ($departemen->whereIn('id', $fDepartemen) as $d)<a href="{{ $tanpa('departemen_id', $d->id) }}" class="chip-filter"><span class="label">Dept</span> {{ $d->nama }}<span class="x"><i data-lucide="x"></i></span></a>@endforeach
            @foreach ($fJenis as $j)<a href="{{ $tanpa('jenis', $j) }}" class="chip-filter"><span class="label">Jenis</span> {{ $jenisOpsi[$j] }}<span class="x"><i data-lucide="x"></i></span></a>@endforeach
            @if ($adaFilter)<a href="{{ route('absensi.index') }}" class="reset">Reset semua</a>@endif
        </div>
    </form>

    <div class="table-responsive">
        <table class="table table-hover">
            <thead><tr><th>Tanggal</th><th>Karyawan</th><th>Jenis</th><th>Detail</th><th>Keterangan</th><th>Diinput oleh</th><th>Aksi</th></tr></thead>
            <tbody>
            @forelse ($data as $r)
                <tr>
                    <td class="fw-semibold text-nowrap">{{ $r->tanggal->translatedFormat('D, j M') }}</td>
                    <td>{!! orang($r->karyawan->nama, $r->karyawan->nik . ' · ' . $r->karyawan->departemen?->nama) !!}</td>
                    <td>{!! $r->status === 'lembur' ? '<span class="badge badge-soft-primary">Lembur</span>' : badge_status($r->status) !!}</td>
                    <td class="text-nowrap">{{ $r->detail }}</td>
                    <td class="text-secondary">{{ $r->ket ?? '-' }}</td>
                    <td class="small">{{ $r->oleh }}</td>
                    <td class="text-nowrap">@include('partials.aksi', [
                        'ubah' => request()->fullUrlWithQuery(['ubah' => $r->tipe . '-' . $r->id, 'baru' => null]),
                        'hapus' => route(['absensi' => 'absensi.destroy', 'cuti' => 'cuti.destroy', 'lembur' => 'lembur.destroy'][$r->tipe], $r->id),
                        'pesanHapus' => 'Hapus data ' . ($r->status === 'lembur' ? 'lembur' : $r->status) . ' ' . $r->karyawan->nama . ' tanggal ' . $r->tanggal->translatedFormat('j M') . '?' . ($r->status === 'cuti' ? ' Sisa cutinya dikembalikan.' : ''),
                    ])</td>
                </tr>
            @empty
                <tr><td colspan="7"><x-empty ikon="search-x" judul="Tidak ada data" pesan="Coba ubah rentang tanggal atau reset filter." /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @include('partials.halaman', ['data' => $data, 'label' => 'catatan'])
</div>

{{-- Drawer: tambah / ubah --}}
<x-panel :judul="$ubah ? 'Ubah Data' : 'Tambah Data'" :sub="$ubah ? $m?->karyawan?->nama : 'Pilih jenis data yang mau diinput'">
    @unless ($ubah)
        <div class="chips mb-3" id="pilihJenis">
            @foreach (['absensi' => 'Absensi', 'cuti' => 'Cuti / Izin / Sakit', 'lembur' => 'Lembur'] as $v => $l)
                <button type="button" class="chip {{ $aktif === $v ? 'active' : '' }}" data-jenis="{{ $v }}">{{ $l }}</button>
            @endforeach
        </div>
    @endunless

    {{-- Absensi --}}
    <form method="post" action="{{ $tipeUbah === 'absensi' ? route('absensi.update', $m) : route('absensi.store') }}" data-jenis-form="absensi" class="{{ $aktif === 'absensi' ? '' : 'd-none' }}">
        @csrf @if ($tipeUbah === 'absensi') @method('put') @endif <input type="hidden" name="_jenis" value="absensi">
        @include('absensi._pilih-karyawan', ['nilai' => $tipeUbah === 'absensi' ? $m->karyawan_id : null])
        <div class="mb-field"><label class="form-label">Tanggal</label><input type="date" name="tanggal" value="{{ old('tanggal', $tipeUbah === 'absensi' ? $m->tanggal->toDateString() : now()->toDateString()) }}" class="form-control" required></div>
        <div class="mb-field"><label class="form-label">Status</label>
            <select name="status" class="form-select">@foreach (\App\Models\Attendance::STATUS as $v => $l)<option value="{{ $v }}" @selected(old('status', $tipeUbah === 'absensi' ? $m->status : 'hadir') === $v)>{{ $l }}</option>@endforeach</select></div>
        <div class="row g-2 mb-field">
            <div class="col"><label class="form-label">Jam masuk</label><input type="time" name="jam_masuk" value="{{ old('jam_masuk', $tipeUbah === 'absensi' && $m->jam_masuk ? substr($m->jam_masuk, 0, 5) : '08:00') }}" class="form-control"></div>
            <div class="col"><label class="form-label">Jam keluar</label><input type="time" name="jam_keluar" value="{{ old('jam_keluar', $tipeUbah === 'absensi' && $m->jam_keluar ? substr($m->jam_keluar, 0, 5) : '17:00') }}" class="form-control"></div>
        </div>
        <x-catatan>Status alfa mengosongkan jam masuk dan keluar, dan memotong gaji pokok per hari.</x-catatan>
        <div class="tombol"><a href="{{ $kembali }}" class="btn btn-light">Batal</a><button class="btn btn-primary">Simpan</button></div>
    </form>

    {{-- Cuti / izin / sakit --}}
    <form method="post" action="{{ $tipeUbah === 'cuti' ? route('cuti.update', $m) : route('cuti.store') }}" data-jenis-form="cuti" class="{{ $aktif === 'cuti' ? '' : 'd-none' }}">
        @csrf @if ($tipeUbah === 'cuti') @method('put') @endif <input type="hidden" name="_jenis" value="cuti">
        @include('absensi._pilih-karyawan', ['nilai' => $tipeUbah === 'cuti' ? $m->karyawan_id : null, 'sisa' => true])
        <div class="mb-field"><label class="form-label">Jenis</label>
            <select name="jenis" class="form-select">@foreach (\App\Models\Leave::JENIS as $v => $l)<option value="{{ $v }}" @selected(old('jenis', $tipeUbah === 'cuti' ? $m->jenis : 'cuti') === $v)>{{ $l }}</option>@endforeach</select></div>
        <div class="row g-2 mb-field">
            <div class="col"><label class="form-label">Mulai</label><input type="date" name="tgl_mulai" value="{{ old('tgl_mulai', $tipeUbah === 'cuti' ? $m->tgl_mulai->toDateString() : now()->toDateString()) }}" class="form-control" required></div>
            <div class="col"><label class="form-label">Selesai</label><input type="date" name="tgl_selesai" value="{{ old('tgl_selesai', $tipeUbah === 'cuti' ? $m->tgl_selesai->toDateString() : now()->toDateString()) }}" class="form-control" required></div>
        </div>
        <div class="mb-field"><label class="form-label">Keterangan</label><textarea name="keterangan" rows="2" class="form-control" placeholder="mis. Surat dokter">{{ old('keterangan', $tipeUbah === 'cuti' ? $m->keterangan : '') }}</textarea></div>
        <x-catatan>Cuti mengurangi sisa jatah sebanyak hari kerja (Senin–Jumat). Izin dan sakit tidak mengurangi jatah.</x-catatan>
        <div class="tombol"><a href="{{ $kembali }}" class="btn btn-light">Batal</a><button class="btn btn-primary">Simpan</button></div>
    </form>

    {{-- Lembur --}}
    <form method="post" action="{{ $tipeUbah === 'lembur' ? route('lembur.update', $m) : route('lembur.store') }}" data-jenis-form="lembur" class="{{ $aktif === 'lembur' ? '' : 'd-none' }}">
        @csrf @if ($tipeUbah === 'lembur') @method('put') @endif <input type="hidden" name="_jenis" value="lembur">
        @include('absensi._pilih-karyawan', ['nilai' => $tipeUbah === 'lembur' ? $m->karyawan_id : null, 'lemburSaja' => true])
        <div class="mb-field"><label class="form-label">Tanggal</label><input type="date" name="tanggal" value="{{ old('tanggal', $tipeUbah === 'lembur' ? $m->tanggal->toDateString() : now()->toDateString()) }}" class="form-control" required></div>
        <div class="row g-2 mb-field">
            <div class="col"><label class="form-label">Jam mulai</label><input type="time" name="jam_mulai" value="{{ old('jam_mulai', $tipeUbah === 'lembur' ? substr($m->jam_mulai, 0, 5) : '17:00') }}" class="form-control" required></div>
            <div class="col"><label class="form-label">Jam selesai</label><input type="time" name="jam_selesai" value="{{ old('jam_selesai', $tipeUbah === 'lembur' ? substr($m->jam_selesai, 0, 5) : '19:00') }}" class="form-control" required></div>
        </div>
        <div class="mb-field"><label class="form-label">Keterangan</label><textarea name="keterangan" rows="2" class="form-control" placeholder="mis. Closing gudang akhir bulan">{{ old('keterangan', $tipeUbah === 'lembur' ? $m->keterangan : '') }}</textarea></div>
        <x-catatan>Upah lembur = 1/173 × gaji pokok × 1,5 (jam pertama) dan × 2 (jam berikutnya). Hanya karyawan yang kontraknya punya komponen lembur.</x-catatan>
        <div class="tombol"><a href="{{ $kembali }}" class="btn btn-light">Batal</a><button class="btn btn-primary">Simpan</button></div>
    </form>
</x-panel>
@push('scripts')
<script>
    document.querySelectorAll('#pilihJenis [data-jenis]').forEach(b => b.addEventListener('click', () => {
        document.querySelectorAll('#pilihJenis .chip').forEach(c => c.classList.toggle('active', c === b));
        document.querySelectorAll('[data-jenis-form]').forEach(f => f.classList.toggle('d-none', f.dataset.jenisForm !== b.dataset.jenis));
    }));
</script>
@endpush
@endsection
