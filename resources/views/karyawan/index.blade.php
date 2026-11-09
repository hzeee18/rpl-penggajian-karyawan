@extends('layouts.app')
@section('title', 'Data Karyawan')
@section('breadcrumb', 'Karyawan / Data Karyawan')
@section('content')
<x-page-header judul="Data Karyawan" sub="Data karyawan aktif beserta departemen dan status PTKP-nya.">
    <a href="{{ route('karyawan.create') }}" class="btn btn-primary"><i data-lucide="plus"></i> Tambah Karyawan</a>
</x-page-header>

<div class="md-grid">
    <div class="card">
        <x-cari placeholder="Cari nama atau NIK..." />
        <div class="table-responsive">
            <table class="table table-hover">
                <thead><tr><th>NIK</th><th>Departemen</th><th>Karyawan</th><th>PTKP</th><th>Aksi</th></tr></thead>
                <tbody>
                @forelse ($karyawan as $k)
                    <tr class="{{ $ubah?->id === $k->id ? 'terpilih' : '' }}">
                        <td class="kode">{{ $k->nik }}</td>
                        <td>{{ $k->departemen?->nama }}</td>
                        <td><a href="{{ route('karyawan.show', $k) }}" class="text-reset">{!! orang($k->nama, $k->jabatan) !!}</a></td>
                        <td>{{ $k->pajak?->status_ptkp ?? '-' }}</td>
                        <td class="text-nowrap">@include('partials.aksi', ['ubah' => route('karyawan.index', ['ubah' => $k->id] + request()->only('q', 'page')), 'hapus' => route('karyawan.destroy', $k), 'pesanHapus' => 'Hapus ' . $k->nama . '? Kalau sudah punya riwayat gaji, karyawan hanya dinonaktifkan.'])</td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-empty :ikon="request('q') ? 'search-x' : 'users'" :judul="request('q') ? 'Tidak ada hasil' : 'Belum ada data'" :pesan="request('q') ? 'Coba kata kunci lain.' : 'Data yang ditambahkan akan muncul di sini.'"><a href="{{ route('karyawan.create') }}" class="btn btn-primary">Tambah data</a></x-empty></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($karyawan->lastPage() > 1) @include('partials.halaman', ['data' => $karyawan, 'label' => 'karyawan']) @endif
    </div>

    @if ($ubah)
        <x-panel judul="Ubah Karyawan" :sub="$ubah->nik . ' · ' . $ubah->nama" :action="route('karyawan.update', $ubah)" method="put">
            <input type="hidden" name="kembali" value="index">
            @foreach (['nik', 'jabatan', 'jenis_kelamin', 'email', 'telepon', 'alamat', 'nama_bank', 'no_rekening', 'status_kepegawaian', 'sisa_cuti'] as $f)
                <input type="hidden" name="{{ $f }}" value="{{ $ubah->$f }}">
            @endforeach
            <input type="hidden" name="tgl_masuk" value="{{ $ubah->tgl_masuk?->toDateString() }}">
            @if ($ubah->is_active)<input type="hidden" name="is_active" value="1">@endif
            <div class="mb-field"><label class="form-label">Nama lengkap</label><input name="nama" value="{{ old('nama', $ubah->nama) }}" class="form-control" required></div>
            <div class="mb-field"><label class="form-label">Departemen</label>
                <select name="departemen_id" class="form-select">@foreach ($departemen as $d)<option value="{{ $d->id }}" @selected(old('departemen_id', $ubah->departemen_id) == $d->id)>{{ $d->nama }}</option>@endforeach</select></div>
            <div class="mb-field"><label class="form-label">Status PTKP</label>
                <select name="pajak_id" class="form-select">@foreach ($pajak as $p)<option value="{{ $p->id }}" @selected(old('pajak_id', $ubah->pajak_id) == $p->id)>{{ $p->status_ptkp }}</option>@endforeach</select></div>
            <x-catatan>NIK wajib unik. Status PTKP dipakai untuk menghitung PPh21 TER.</x-catatan>
            <div class="tombol">
                <a href="{{ route('karyawan.edit', $ubah) }}" class="btn btn-ghost me-auto px-0">Form lengkap</a>
                <a href="{{ route('karyawan.index') }}" class="btn btn-light">Batal</a><button class="btn btn-primary">Simpan</button>
            </div>
        </x-panel>
    @endif
</div>
@endsection
