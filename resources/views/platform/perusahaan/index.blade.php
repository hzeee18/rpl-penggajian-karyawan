@extends('layouts.app')
@section('title', 'Perusahaan')
@section('breadcrumb', 'Platform / Perusahaan')
@section('content')
<x-page-header judul="Perusahaan" sub="Kelola perusahaan dan akun Admin-nya. Isi perusahaan dikelola Admin perusahaan.">
    <a href="{{ route('perusahaan.index', ['baru' => 1]) }}" class="btn btn-primary"><i data-lucide="plus"></i> Tambah Perusahaan</a>
</x-page-header>

<div class="md-grid">
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead><tr><th>Kode</th><th>Perusahaan</th><th class="text-center">Karyawan</th><th class="text-center">Pengguna</th><th>Status</th><th>Aksi</th></tr></thead>
                <tbody>
                @forelse ($perusahaan as $p)
                    <tr class="{{ $ubah->id === $p->id ? 'terpilih' : '' }}">
                        <td class="kode">{{ $p->code }}</td>
                        <td>{{ $p->name }}</td>
                        <td class="text-center">{{ $karyawan[$p->id] ?? 0 }}</td>
                        <td class="text-center">{{ $pengguna[$p->id] ?? 0 }}</td>
                        <td>{!! badge_status($p->is_active ? 'aktif' : 'nonaktif') !!}</td>
                        <td class="text-nowrap"><div class="d-inline-flex gap-2">
                            <a href="{{ route('perusahaan.index', ['ubah' => $p->id]) }}" class="btn btn-light btn-icon" title="Ubah"><i data-lucide="pencil"></i></a>
                            <a href="{{ route('perusahaan.index', ['admin' => $p->id]) }}" class="btn btn-light btn-icon" title="Akun Admin perusahaan"><i data-lucide="user-cog"></i></a>
                        </div></td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-empty ikon="building" judul="Belum ada perusahaan" /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <x-panel :buka="request()->hasAny(['ubah', 'baru']) || ($errors->any() && ! $adminUntuk)" :judul="$ubah->exists ? 'Ubah Perusahaan' : 'Tambah Perusahaan'" :sub="$ubah->exists ? $ubah->code . ' · ' . $ubah->name : 'Role dan hak akses bawaan disalin dari perusahaan DEFAULT'"
             :action="$ubah->exists ? route('perusahaan.update', $ubah) : route('perusahaan.store')" :method="$ubah->exists ? 'put' : 'post'" enctype="multipart/form-data">
        @unless ($ubah->exists)
            <div class="mb-field"><label class="form-label">Kode perusahaan</label><input name="code" value="{{ old('code') }}" class="form-control" maxlength="32" required></div>
        @endunless
        <div class="mb-field"><label class="form-label">Nama perusahaan</label><input name="name" value="{{ old('name', $ubah->name) }}" class="form-control" required></div>
        <div class="mb-field"><label class="form-label">Alamat</label><textarea name="address" class="form-control" rows="2">{{ old('address', $ubah->address) }}</textarea></div>
        <div class="mb-field"><label class="form-label">Penanda tangan slip</label><input name="signer_name" value="{{ old('signer_name', $ubah->signer_name) }}" class="form-control"></div>
        <div class="mb-field"><label class="form-label">Jabatan penanda tangan</label><input name="signer_title" value="{{ old('signer_title', $ubah->signer_title) }}" class="form-control"></div>
        @if ($ubah->exists)
            <div class="mb-field"><label class="form-label">Gambar tanda tangan (PNG/JPG, maks. 1 MB)</label><input type="file" name="tanda_tangan" accept="image/png,image/jpeg" class="form-control">
                <div class="form-text">{{ $ubah->signature_path ? 'Sudah ada. Unggah untuk mengganti.' : 'Belum ada.' }}</div></div>
            <input type="hidden" name="is_active" value="0">
            <div class="form-check mb-field"><input type="checkbox" name="is_active" value="1" class="form-check-input" id="aktif" @checked(old('is_active', $ubah->is_active))><label class="form-check-label" for="aktif">Aktif</label></div>
        @endif
        @unless ($ubah->exists)
            <h6 class="text-muted mt-3 mb-2">Akun Admin perusahaan</h6>
            <div class="form-text mb-2">Admin ini yang login untuk mengelola user, role, dan data perusahaan.</div>
            @include('platform.perusahaan._akun-admin')
        @endunless
        <div class="tombol"><a href="{{ route('perusahaan.index') }}" class="btn btn-light">Batal</a><button class="btn btn-primary">Simpan</button></div>
    </x-panel>

    @if ($adminUntuk)
        <x-panel id="drawer-admin" judul="Admin Perusahaan" :sub="$adminUntuk->code . ' · ' . $adminUntuk->name" :action="route('perusahaan.admin.store', $adminUntuk)" method="post" :buka="true">
            @forelse ($adminPerusahaan as $a)
                <div class="d-flex justify-content-between mb-2"><span class="fw-semibold">{{ $a->username }}</span><span class="small text-muted">{{ $a->email ?? '-' }} · {{ $a->is_active ? 'aktif' : 'nonaktif' }}</span></div>
            @empty
                <div class="small text-muted mb-2">Belum ada akun Admin. Tambahkan agar perusahaan bisa dikelola.</div>
            @endforelse
            <h6 class="text-muted mt-3 mb-2">Tambah akun Admin</h6>
            @include('platform.perusahaan._akun-admin')
            <div class="tombol"><a href="{{ route('perusahaan.index') }}" class="btn btn-light">Tutup</a><button class="btn btn-primary">Tambah Admin</button></div>
        </x-panel>
    @endif
</div>
@endsection
