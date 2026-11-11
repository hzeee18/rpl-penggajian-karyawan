@extends('layouts.app')
@section('title', 'Perusahaan')
@section('breadcrumb', 'Platform / Perusahaan')
@section('content')
<x-page-header judul="Perusahaan" sub="Kelola perusahaan dan pilih perusahaan yang sedang dikerjakan.">
    @if ($aktif)
        <form method="post" action="{{ route('perusahaan.keluar') }}">@csrf<button class="btn btn-light"><i data-lucide="log-out"></i> Keluar dari perusahaan aktif</button></form>
    @endif
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
                        <td>{{ $p->name }}@if ($aktif === $p->id) <span class="badge badge-soft-primary">Aktif dikerjakan</span>@endif</td>
                        <td class="text-center">{{ $karyawan[$p->id] ?? 0 }}</td>
                        <td class="text-center">{{ $pengguna[$p->id] ?? 0 }}</td>
                        <td>{!! badge_status($p->is_active ? 'aktif' : 'nonaktif') !!}</td>
                        <td class="text-nowrap"><div class="d-inline-flex gap-2">
                            <a href="{{ route('perusahaan.index', ['ubah' => $p->id]) }}" class="btn btn-light btn-icon" title="Ubah"><i data-lucide="pencil"></i></a>
                            @if ($p->is_active && $aktif !== $p->id)
                                <form method="post" action="{{ route('perusahaan.pilih', $p) }}">@csrf<button class="btn btn-outline-primary btn-sm">Pilih</button></form>
                            @endif
                        </div></td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-empty ikon="building" judul="Belum ada perusahaan" /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <x-panel :judul="$ubah->exists ? 'Ubah Perusahaan' : 'Tambah Perusahaan'" :sub="$ubah->exists ? $ubah->code . ' · ' . $ubah->name : 'Role dan hak akses bawaan disalin dari perusahaan DEFAULT'"
             :action="$ubah->exists ? route('perusahaan.update', $ubah) : route('perusahaan.store')" :method="$ubah->exists ? 'put' : 'post'">
        @unless ($ubah->exists)
            <div class="mb-field"><label class="form-label">Kode perusahaan</label><input name="code" value="{{ old('code') }}" class="form-control" maxlength="32" required></div>
        @endunless
        <div class="mb-field"><label class="form-label">Nama perusahaan</label><input name="name" value="{{ old('name', $ubah->name) }}" class="form-control" required></div>
        <div class="mb-field"><label class="form-label">Alamat</label><textarea name="address" class="form-control" rows="2">{{ old('address', $ubah->address) }}</textarea></div>
        <div class="mb-field"><label class="form-label">Penanda tangan slip</label><input name="signer_name" value="{{ old('signer_name', $ubah->signer_name) }}" class="form-control"></div>
        <div class="mb-field"><label class="form-label">Jabatan penanda tangan</label><input name="signer_title" value="{{ old('signer_title', $ubah->signer_title) }}" class="form-control"></div>
        @if ($ubah->exists)
            <input type="hidden" name="is_active" value="0">
            <div class="form-check mb-field"><input type="checkbox" name="is_active" value="1" class="form-check-input" id="aktif" @checked(old('is_active', $ubah->is_active))><label class="form-check-label" for="aktif">Aktif</label></div>
        @endif
        <div class="tombol"><a href="{{ route('perusahaan.index') }}" class="btn btn-light">Batal</a><button class="btn btn-primary">Simpan</button></div>
    </x-panel>
</div>
@endsection
