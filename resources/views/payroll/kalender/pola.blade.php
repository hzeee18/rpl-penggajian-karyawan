@extends('layouts.app')
@section('title', 'Kalender Payroll')
@section('breadcrumb', 'Payroll / Kalender Payroll')
@section('content')
@php
    $u = auth()->user();
    $bisaUbah = $u->bisa('pay-libur', 'update');
    $bawaan = $pola->firstWhere('is_default', true);
    $namaBawaan = $bawaan ? $bawaan->name : 'Senin–Jumat';
@endphp
<x-page-header judul="Kalender Payroll" sub="Hari apa saja yang dihitung hari kerja. Dipakai untuk variabel workdays, fungsi WORKDAYS, dan prorata hari kerja.">
    @if ($u->bisa('pay-libur', 'create'))<a href="{{ route('pay.libur.index', ['tab' => 'pola', 'pola_baru' => 1]) }}" class="btn btn-primary"><i data-lucide="plus"></i> Tambah pola</a>@endif
</x-page-header>
@include('payroll.kalender._tab', ['aktif' => 'pola'])
@if ($errors->any())<div class="alert alert-danger">{{ implode(' ', $errors->all()) }}</div>@endif
<div class="mb-3"><x-catatan>Sistem memakai pola pengecualian karyawan lebih dulu, lalu pola departemen. Kalau keduanya belum diatur, dipakai pola bawaan: {{ $namaBawaan }}. Hari libur tetap mengurangi hari kerja; libur khusus departemen hanya berlaku untuk departemen itu. Perubahan pola berlaku untuk payroll yang belum di-generate.</x-catatan></div>

<div class="md-grid">
<div>
    <div class="card mb-3"><div class="card-body pb-0 fw-semibold">Pola hari kerja</div>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr><th>Kode</th><th>Nama</th><th>Hari kerja</th><th></th><th class="text-center">Aksi</th></tr></thead>
        <tbody>
        @forelse ($pola as $p)
            <tr class="{{ $ubah?->id === $p->id ? 'terpilih' : '' }}"><td class="kode">{{ $p->code }}</td><td>{{ $p->name }}</td><td>{{ $p->ringkas() }} <span class="text-muted small">({{ count($p->hari()) }} hari/minggu)</span></td>
                <td>@if ($p->is_default)<span class="badge badge-soft-primary">Bawaan</span>@endif</td>
                <td class="text-nowrap text-center"><a href="{{ route('pay.libur.index', ['tab' => 'pola', 'pola_ubah' => $p->id]) }}" class="btn btn-light btn-icon" title="Ubah"><i data-lucide="pencil"></i></a>
                    @if ($u->bisa('pay-libur', 'delete'))<form method="post" action="{{ route('pay.libur.pola.destroy', $p->id) }}" class="d-inline" data-confirm="Hapus pola {{ $p->name }}? Departemen dan karyawan yang memakainya kembali ke pola bawaan.">@csrf @method('delete')<button class="btn btn-outline-danger btn-icon" title="Hapus"><i data-lucide="trash-2"></i></button></form>@endif</td></tr>
        @empty
            <tr><td colspan="5"><x-empty ikon="calendar-clock" judul="Belum ada pola" pesan="Tanpa pola, semua karyawan dihitung kerja Senin–Jumat. Tambah pola, mis. Senin–Minggu untuk operasional." /></td></tr>
        @endforelse
        </tbody></table></div></div>

    <div class="card mb-3"><div class="card-body pb-0 fw-semibold">Pola per departemen</div>
    <div class="table-responsive"><table class="table">
        <thead><tr><th>Departemen</th><th>Pola</th></tr></thead>
        <tbody>
        @foreach ($departemen as $dp)
            <tr><td>{{ $dp->nama }}</td><td>
                <form method="post" action="{{ route('pay.libur.pola.pasang') }}" class="d-flex gap-2">@csrf @method('put')<input type="hidden" name="departemen_id" value="{{ $dp->id }}">
                    <select name="pola_id" class="form-select form-select-sm" style="max-width:280px" @disabled(! $bisaUbah)><option value="" data-ket="Mengikuti pola bawaan perusahaan">Pola bawaan ({{ $namaBawaan }})</option>
                        @foreach ($pola as $p)<option value="{{ $p->id }}" data-ket="{{ $p->ringkas() }}" @selected($dp->pay_work_pattern_id === $p->id)>{{ $p->name }}</option>@endforeach</select>
                    @if ($bisaUbah)<button class="btn btn-light btn-icon" title="Simpan pola departemen"><i data-lucide="check"></i></button>@endif</form></td></tr>
        @endforeach
        </tbody></table></div></div>

    <div class="card"><div class="card-body pb-0"><div class="fw-semibold">Pengecualian karyawan</div><div class="small text-muted">Untuk karyawan yang hari kerjanya berbeda dari departemennya.</div></div>
    <div class="table-responsive"><table class="table">
        <thead><tr><th>Karyawan</th><th>Departemen</th><th>Pola</th><th class="text-center">Aksi</th></tr></thead>
        <tbody>
        @foreach ($pengecualian as $k)
            <tr><td>{{ $k->nik }} · {{ $k->nama }}</td><td>{{ $k->departemen?->nama }}</td><td>{{ $pola->firstWhere('id', $k->pay_work_pattern_id)?->name }}</td>
                <td class="text-center">@if ($bisaUbah)<form method="post" action="{{ route('pay.libur.pola.pasang') }}">@csrf @method('put')<input type="hidden" name="employee_id" value="{{ $k->id }}"><button class="btn btn-outline-danger btn-icon" title="Hapus pengecualian (kembali ke pola departemen)"><i data-lucide="trash-2"></i></button></form>@endif</td></tr>
        @endforeach
        @if ($bisaUbah && $pola->isNotEmpty())
            <tr><td colspan="4"><form method="post" action="{{ route('pay.libur.pola.pasang') }}" class="d-flex flex-wrap gap-2">@csrf @method('put')
                <select name="employee_id" class="form-select form-select-sm" style="max-width:320px" required><option value="">Pilih karyawan</option>@foreach ($karyawan as $k)<option value="{{ $k->id }}">{{ $k->nik }} · {{ $k->nama }}</option>@endforeach</select>
                <select name="pola_id" class="form-select form-select-sm" style="max-width:240px" required>@foreach ($pola as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select>
                <button class="btn btn-primary btn-sm">Tambah pengecualian</button></form></td></tr>
        @endif
        </tbody></table></div></div>
</div>

    @if ($ubah)
        <x-panel :buka="true" :judul="$ubah->exists ? 'Ubah pola ' . $ubah->name : 'Tambah pola hari kerja'" sub="Centang hari yang dihitung hari kerja"
                 :action="$ubah->exists ? route('pay.libur.pola.update', $ubah->id) : route('pay.libur.pola.store')" :method="$ubah->exists ? 'put' : 'post'">
            <div class="mb-field"><label class="form-label">Kode</label><input name="code" value="{{ old('code', $ubah->code) }}" class="form-control" placeholder="mis. operasional" required>
                <div class="form-text">Huruf, angka, spasi, atau strip. Otomatis disimpan huruf kecil, mis. "KW Shift" jadi kw_shift.</div></div>
            <div class="mb-field"><label class="form-label">Nama</label><input name="name" value="{{ old('name', $ubah->name) }}" class="form-control" placeholder="mis. Operasional Senin–Minggu" required></div>
            <div class="mb-field"><label class="form-label">Hari kerja</label><div class="d-flex flex-wrap gap-3">
                @foreach (\App\Domain\Payroll\Models\WorkPattern::NAMA_HARI as $n => $nama)
                    <div class="form-check"><input type="checkbox" name="hari[]" value="{{ $n }}" id="hari{{ $n }}" class="form-check-input" @checked(in_array($n, old('hari', $ubah->hari())))><label for="hari{{ $n }}" class="form-check-label">{{ $nama }}</label></div>
                @endforeach</div></div>
            <div class="form-check mb-field"><input type="checkbox" name="is_default" value="1" id="bawaanPola" class="form-check-input" @checked(old('is_default', $ubah->is_default))><label for="bawaanPola" class="form-check-label">Jadikan pola bawaan perusahaan</label></div>
            <div class="tombol"><a href="{{ route('pay.libur.index', ['tab' => 'pola']) }}" class="btn btn-light">Batal</a><button class="btn btn-primary">Simpan</button></div>
        </x-panel>
    @endif
</div>
@endsection
