@extends('layouts.app')
@section('title', 'Karyawan Payroll')
@section('breadcrumb', 'Payroll Baru / Karyawan Payroll')
@section('content')
<x-page-header judul="Karyawan Payroll" sub="Data tambahan untuk payroll: jabatan, tanggal resign, NPWP. Data inti dikelola di menu Data Karyawan." />

<div class="md-grid">
    <div class="card">
        <x-cari placeholder="Cari nama atau nomor karyawan..." />
        <div class="table-responsive">
            <table class="table table-hover">
                <thead><tr><th>No. Karyawan</th><th>Nama</th><th>Departemen</th><th>Jabatan</th><th>PTKP</th><th>NPWP</th><th>Rekening</th><th>Aksi</th></tr></thead>
                <tbody>
                @forelse ($karyawan as $k)
                    <tr class="{{ $ubah?->id === $k->id ? 'terpilih' : '' }}">
                        <td class="kode">{{ $k->employeeNo() }}</td>
                        <td>{{ $k->nama }}@if ($k->resign_date)<div class="sub text-muted">Resign {{ tgl($k->resign_date) }}</div>@endif</td>
                        <td>{{ $k->departemen?->nama }}</td>
                        <td>{{ $k->position?->name ?? '-' }}</td>
                        <td>{{ $k->ptkpStatus() ?? '-' }}</td>
                        <td>{!! badge_status($k->has_npwp ? 'aktif' : 'nonaktif') !!}</td>
                        <td>{{ $k->rekeningSamaran() ?? '-' }}</td>
                        <td><a href="{{ request()->fullUrlWithQuery(['ubah' => $k->id]) }}" class="btn btn-light btn-icon" title="Ubah"><i data-lucide="pencil"></i></a></td>
                    </tr>
                @empty
                    <tr><td colspan="8"><x-empty ikon="users" judul="Belum ada karyawan" /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($karyawan->lastPage() > 1) @include('partials.halaman', ['data' => $karyawan, 'label' => 'karyawan']) @endif
    </div>

    @if ($ubah)
        <x-panel judul="Data payroll karyawan" :sub="$ubah->employeeNo() . ' · ' . $ubah->nama" :buka="true"
                 :action="route('pay.karyawan.update', $ubah->id) . '?' . http_build_query(request()->only('page', 'q'))" method="put">
            <div class="mb-field"><label class="form-label">Jabatan</label>
                <select name="position_id" class="form-select"><option value="">— Belum diatur —</option>
                    @foreach ($jabatan as $j)<option value="{{ $j->id }}" @selected(old('position_id', $ubah->position_id) == $j->id)>{{ $j->name }}</option>@endforeach</select></div>
            <div class="mb-field"><label class="form-label">Tanggal resign</label><input type="date" name="resign_date" value="{{ old('resign_date', $ubah->resign_date?->format('Y-m-d')) }}" class="form-control"></div>
            <div class="form-check mb-field"><input type="checkbox" name="has_npwp" value="1" class="form-check-input" id="npwp" @checked(old('has_npwp', $ubah->has_npwp))><label class="form-check-label" for="npwp">Punya NPWP</label></div>
            <div class="mb-field"><label class="form-label">NPWP</label><input name="npwp" class="form-control" autocomplete="off" placeholder="{{ $ubah->npwp ? 'Tersimpan (terenkripsi). Isi untuk mengganti.' : 'Belum diisi' }}">
                <div class="form-text">Disimpan terenkripsi dan tidak ditampilkan ulang.</div></div>
            @if (auth()->user()->bisa('pay-karyawan', 'update'))
                <div class="tombol"><a href="{{ route('pay.karyawan.index', request()->only('page', 'q')) }}" class="btn btn-light">Batal</a><button class="btn btn-primary">Simpan</button></div>
            @endif
        </x-panel>
    @endif
</div>
@endsection
