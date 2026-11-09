@extends('layouts.app')
@section('title', 'Manajemen User')
@section('breadcrumb', 'Pengaturan / User')
@section('content')
<x-page-header judul="Manajemen User" sub="Akun login untuk setiap karyawan dan role-nya.">
    <a href="{{ route('users.create') }}" class="btn btn-primary"><i data-lucide="plus"></i> Tambah User</a>
</x-page-header>

@if ($tanpaAkun)
    <div class="mb-3"><x-catatan tipe="info">Ada {{ $tanpaAkun }} karyawan aktif yang belum punya akun. <a href="{{ route('users.create') }}" class="fw-semibold">Buatkan akun</a></x-catatan></div>
@endif

<div class="card">
    <x-cari placeholder="Cari nama atau email...">
        <select name="role_id" class="form-select" style="width:180px" onchange="this.form.submit()"><option value="">Semua role</option>@foreach ($roles as $r)<option value="{{ $r->id }}" @selected(request('role_id') == $r->id)>{{ $r->nama }}</option>@endforeach</select>
        <select name="status" class="form-select" style="width:180px" onchange="this.form.submit()"><option value="">Semua status</option>@foreach (['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif', 'terkunci' => 'Terkunci'] as $v => $l)<option value="{{ $v }}" @selected(request('status') === $v)>{{ $l }}</option>@endforeach</select>
    </x-cari>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead><tr><th>Pengguna</th><th>Role</th><th>Departemen</th><th>Status</th><th>Login terakhir</th><th>Aksi</th></tr></thead>
            <tbody>
            @forelse ($users as $u)
                <tr>
                    <td>{!! orang($u->namaTampil(), $u->email ?? $u->username) !!}</td>
                    <td><span class="badge badge-role-{{ strtolower($u->role->nama) }}">{{ $u->role->nama }}</span></td>
                    <td>{{ $u->karyawan?->departemen?->nama ?? '-' }}</td>
                    <td>
                        @if ($u->is_locked)<span class="badge badge-soft-danger">Terkunci</span>
                        @elseif ($u->is_active)<span class="badge badge-soft-success">Aktif</span>
                        @else<span class="badge badge-soft-danger">Nonaktif</span>@endif
                    </td>
                    <td class="text-nowrap">{{ $u->last_login?->translatedFormat('j M H.i') ?? '-' }}</td>
                    <td class="text-nowrap">
                        <a href="{{ route('users.edit', $u) }}" class="btn btn-light btn-icon" title="Ubah"><i data-lucide="pencil"></i></a>
                        <div class="dropdown d-inline">
                            <button class="btn btn-light btn-icon" data-bs-toggle="dropdown" title="Lainnya"><i data-lucide="more-vertical"></i></button>
                            <div class="dropdown-menu dropdown-menu-end">
                                <a class="dropdown-item d-flex gap-2" href="{{ route('users.edit', $u) }}"><i data-lucide="pencil"></i> Ubah</a>
                                <form method="post" action="{{ route('users.resetPassword', $u) }}" data-confirm="Reset kata sandi {{ $u->username }}? Kata sandi baru akan ditampilkan.">@csrf<button class="dropdown-item d-flex gap-2"><i data-lucide="key-round"></i> Reset kata sandi</button></form>
                                @if ($u->is_locked)<form method="post" action="{{ route('users.bukaKunci', $u) }}">@csrf<button class="dropdown-item d-flex gap-2"><i data-lucide="lock-open"></i> Buka kunci</button></form>@endif
                                @if ($u->is_active && $u->id !== auth()->id())
                                    <form method="post" action="{{ route('users.destroy', $u) }}" data-confirm="Nonaktifkan akun {{ $u->username }}?">@csrf @method('delete')<button class="dropdown-item d-flex gap-2 text-danger"><i data-lucide="user-x"></i> Nonaktifkan</button></form>
                                @endif
                            </div>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6"><x-empty ikon="search-x" judul="Tidak ada hasil" pesan="Coba kata kunci lain atau reset filter yang dipakai."><a href="{{ route('users.index') }}" class="btn btn-light">Reset filter</a></x-empty></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @include('partials.halaman', ['data' => $users, 'label' => 'pengguna'])
</div>
@endsection
