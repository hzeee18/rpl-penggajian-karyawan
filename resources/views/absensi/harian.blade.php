@extends('layouts.app')
@section('title', 'Absensi, Cuti & Lembur')
@section('breadcrumb', 'Absensi / Input Harian')
@section('content')
<x-page-header judul="Absensi, Cuti & Lembur" sub="Data yang disimpan HR langsung final, tanpa pengajuan dan tanpa approval.">
    <a href="{{ route('import.index', ['jenis' => 'absensi']) }}" class="btn btn-light">Import Excel</a>
</x-page-header>

<div class="tab-nav">
    <a href="{{ route('absensi.index') }}">Semua data</a>
    <a href="{{ route('absensi.index', ['tab' => 'harian']) }}" class="active">Input harian</a>
</div>
<div class="md-grid">
    <div class="card">
        <form class="card-search" method="get"><input type="hidden" name="tab" value="harian">
            <label class="input-search d-block position-relative mb-0">
                <span class="form-control d-flex align-items-center text-secondary">Tanggal: {{ $tanggal->translatedFormat('l, j M Y') }}</span>
                <input type="date" data-native name="tanggal" value="{{ $tanggal->toDateString() }}" onchange="this.form.submit()" onclick="try{this.showPicker()}catch(e){}" style="position:absolute;inset:0;opacity:0;cursor:pointer">
                <i data-lucide="search"></i>
            </label>
        </form>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead><tr><th>NIK</th><th>Status</th><th>Karyawan</th><th class="text-end">Lembur</th><th>Aksi</th></tr></thead>
                <tbody>
                @forelse ($baris as $b)
                    <tr class="{{ $pilih?->karyawan->id === $b->karyawan->id ? 'terpilih' : '' }}">
                        <td class="kode">{{ $b->karyawan->nik }}</td>
                        <td>{!! $b->status ? badge_status($b->status) : '<span class="badge badge-soft-neutral">Belum diisi</span>' !!}</td>
                        <td>{!! orang($b->karyawan->nama, $b->karyawan->departemen?->nama) !!}</td>
                        <td class="text-end">{{ $b->lembur->sum('durasi') ? ($b->lembur->sum('durasi') + 0) . ' jam' : '-' }}</td>
                        <td><a href="{{ route('absensi.index', ['tab' => 'harian', 'tanggal' => $tanggal->toDateString(), 'ubah' => $b->karyawan->id] + request()->only('q', 'page')) }}" class="btn btn-light btn-icon" title="Ubah"><i data-lucide="pencil"></i></a></td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-empty ikon="calendar" judul="Belum ada catatan absensi" pesan="Data absensi, cuti, dan lembur diinput oleh HR." /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="table-foot border-top">
            <span></span>
            @if ($karyawan->lastPage() > 1){{ $karyawan->onEachSide(1)->links('pagination::simple-bootstrap-5') }}@endif
        </div>
    </div>

    @if ($pilih)
        @php $lb = $pilih->lembur->first(); @endphp
        <x-panel judul="Ubah Data Harian" :sub="$tanggal->translatedFormat('j M Y') . ' · ' . $pilih->karyawan->nama" :action="route('absensi.harian')">
            <input type="hidden" name="karyawan_id" value="{{ $pilih->karyawan->id }}">
            <input type="hidden" name="tanggal" value="{{ $tanggal->toDateString() }}">
            <div class="mb-field"><label class="form-label">Status kehadiran</label>
                <select name="status" class="form-select">
                    <option value="">— Belum diisi —</option>
                    @foreach (['hadir' => 'Hadir', 'terlambat' => 'Terlambat', 'sakit' => 'Sakit', 'izin' => 'Izin', 'cuti' => 'Cuti', 'alfa' => 'Alfa'] as $v => $l)
                        <option value="{{ $v }}" @selected(old('status', $pilih->status) === $v)>{{ $l }}</option>
                    @endforeach
                </select></div>
            <div class="mb-field"><label class="form-label">Jam lembur</label>
                <div class="d-flex gap-2 align-items-center">
                    <input type="time" name="lembur_mulai" value="{{ old('lembur_mulai', $lb ? substr($lb->jam_mulai, 0, 5) : '') }}" class="form-control">
                    <span class="text-muted">–</span>
                    <input type="time" name="lembur_selesai" value="{{ old('lembur_selesai', $lb ? substr($lb->jam_selesai, 0, 5) : '') }}" class="form-control">
                </div>
                @if ($lb)<div class="form-text">{{ $lb->durasi + 0 }} jam</div>@endif
            </div>
            <div class="mb-field"><label class="form-label">Keterangan</label><input name="keterangan" value="{{ old('keterangan', $pilih->keterangan) }}" class="form-control" placeholder="mis. Lembur stok opname"></div>
            <x-catatan>Cuti otomatis mengurangi sisa jatah. Lembur hanya untuk karyawan yang kontraknya punya komponen lembur.</x-catatan>
            <div class="tombol"><a href="{{ route('absensi.index', ['tab' => 'harian', 'tanggal' => $tanggal->toDateString()]) }}" class="btn btn-light">Batal</a><button class="btn btn-primary">Simpan</button></div>
        </x-panel>
    @endif
</div>
@endsection
