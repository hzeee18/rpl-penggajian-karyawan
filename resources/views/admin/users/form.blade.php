@extends('layouts.app')
@section('title', $user->exists ? 'Ubah user' : 'Tambah user')
@section('breadcrumb', 'Administrasi / Manajemen User')
@section('content')
<x-page-header :judul="$user->exists ? 'Ubah user' : 'Tambah user'" sub="Akun dipakai untuk login. Hubungkan ke data karyawan kalau bukan Admin." />
<div class="card" style="max-width:640px">
    <form method="post" action="{{ $user->exists ? route('users.update', $user) : route('users.store') }}" class="card-body">
        @csrf @if ($user->exists) @method('put') @endif
        <div class="mb-3">
            <label class="form-label">Karyawan</label>
            <select name="karyawan_id" class="form-select">
                <option value="">— Tidak terhubung karyawan (mis. Admin) —</option>
                @foreach ($karyawan as $k)<option value="{{ $k->id }}" @selected(old('karyawan_id', $user->karyawan_id) == $k->id)>{{ $k->nik }} · {{ $k->nama }}</option>@endforeach
            </select>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3"><label class="form-label">Username</label><input name="username" value="{{ old('username', $user->username) }}" class="form-control" required></div>
            <div class="col-md-6 mb-3"><label class="form-label">Email</label><input type="email" name="email" value="{{ old('email', $user->email) }}" class="form-control"></div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Role</label>
                <select name="role_id" class="form-select" required>@foreach ($roles as $r)<option value="{{ $r->id }}" @selected(old('role_id', $user->role_id) == $r->id)>{{ $r->nama }}</option>@endforeach</select>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Kata sandi {{ $user->exists ? '(kosongkan jika tidak diganti)' : '' }}</label>
                <input type="password" name="password" class="form-control" minlength="8" {{ $user->exists ? '' : 'required' }}>
            </div>
        </div>
        <div class="form-check mb-3"><input type="checkbox" name="is_active" value="1" class="form-check-input" id="aktif" @checked(old('is_active', $user->is_active))><label for="aktif" class="form-check-label">Akun aktif</label></div>
        <a href="{{ route('users.index') }}" class="btn btn-light">Batal</a>
        <button class="btn btn-primary">Simpan</button>
    </form>
</div>
@endsection
