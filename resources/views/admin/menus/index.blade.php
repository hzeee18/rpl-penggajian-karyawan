@extends('layouts.app')
@section('title', 'Manajemen Menu')
@section('breadcrumb', 'Pengaturan / Menu')
@section('content')
<x-page-header judul="Manajemen Menu" sub="Menu aplikasi yang aksesnya diatur per role.">
    <a href="{{ route('menus.index', ['baru' => 1]) }}" class="btn btn-primary"><i data-lucide="plus"></i> Tambah Menu</a>
</x-page-header>

<div class="md-grid">
    <div class="card">
        <x-cari placeholder="Cari menu..." />
        <div class="table-responsive">
            <table class="table table-hover">
                <thead><tr><th>Urut</th><th>Induk</th><th>Menu</th><th class="text-center">Role</th><th>Aksi</th></tr></thead>
                <tbody>
                @forelse ($menus as $m)
                    <tr class="{{ $ubah?->id === $m->id ? 'terpilih' : '' }}">
                        <td>{{ $m->urutan }}</td>
                        <td>{{ $m->parent?->nama ?? '-' }}</td>
                        <td>{!! orang($m->nama, $m->url ? '/' . $m->url : 'Grup menu') !!}</td>
                        <td class="text-center">{{ $m->privileges->where('can_view', true)->count() }}</td>
                        <td class="text-nowrap">@include('partials.aksi', ['ubah' => route('menus.index', ['ubah' => $m->id] + request()->only('q', 'page')), 'hapus' => route('menus.destroy', $m), 'pesanHapus' => 'Hapus menu ' . $m->nama . '? Hak akses menu ini ikut terhapus.'])</td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-empty ikon="search-x" judul="Tidak ada hasil" pesan="Coba kata kunci lain." /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @include('partials.halaman', ['data' => $menus, 'label' => 'menu'])
    </div>

    <x-panel :judul="$ubah ? 'Ubah Menu' : 'Tambah Menu'" :sub="$ubah ? $ubah->nama : 'Menu baru di sidebar'" :action="$ubah ? route('menus.update', $ubah) : route('menus.store')" :method="$ubah ? 'put' : 'post'">
        <div class="mb-field"><label class="form-label">Nama menu</label><input name="nama" value="{{ old('nama', $ubah?->nama) }}" class="form-control" required></div>
        <div class="mb-field"><label class="form-label">Menu induk</label>
            <select name="parent_id" class="form-select"><option value="">— Tanpa induk —</option>@foreach ($induk as $g)<option value="{{ $g->id }}" @selected(old('parent_id', $ubah?->parent_id) == $g->id)>{{ $g->nama }}</option>@endforeach</select></div>
        <div class="mb-field"><label class="form-label">URL</label><input name="url" value="{{ old('url', $ubah?->url) }}" class="form-control" placeholder="/karyawan"></div>
        <div class="row g-2 mb-field">
            <div class="col-8"><label class="form-label">Ikon (Lucide)</label><input name="icon" value="{{ old('icon', $ubah?->icon) }}" class="form-control" placeholder="users"></div>
            <div class="col-4"><label class="form-label">Urutan</label><input type="number" name="urutan" value="{{ old('urutan', $ubah?->urutan) }}" class="form-control"></div>
        </div>
        <x-catatan>Akses tiap role ke menu ini diatur di halaman Role &amp; Privilege.</x-catatan>
        <div class="tombol"><a href="{{ route('menus.index') }}" class="btn btn-light">Batal</a><button class="btn btn-primary">Simpan</button></div>
    </x-panel>
</div>
@endsection
