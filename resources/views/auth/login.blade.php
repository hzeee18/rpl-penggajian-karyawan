@extends('layouts.auth')
@section('title', 'Masuk')
@section('content')
@php $gagal = $errors->has('username'); @endphp
<h1>Selamat datang kembali</h1>
<p class="lead-sub">Masuk dengan akun dari Admin untuk melanjutkan.</p>
<form method="post" action="{{ route('login') }}">
    @csrf
    <div class="mb-4">
        <label class="form-label">Username</label>
        <input name="username" value="{{ old('username') }}" class="form-control {{ $gagal ? 'border-danger' : '' }}" placeholder="Masukkan username" autofocus required>
    </div>
    <div>
        <label class="form-label">Kata sandi</label>
        <div class="position-relative">
            <input type="password" name="password" id="pw" class="form-control pe-5 {{ $gagal ? 'border-danger' : '' }}" placeholder="Masukkan kata sandi" required>
            <button type="button" class="btn p-0 border-0 position-absolute top-50 end-0 translate-middle-y me-3 text-secondary" onclick="pw.type = pw.type === 'password' ? 'text' : 'password'" aria-label="Lihat kata sandi"><i data-lucide="eye"></i></button>
        </div>
    </div>
    @if ($gagal)<div class="login-error">{{ $errors->first('username') }}</div>@endif
    <div class="d-flex justify-content-between align-items-center my-4">
        <div class="form-check mb-0"><input type="checkbox" class="form-check-input" id="ingat" name="ingat" value="1"><label class="form-check-label" for="ingat">Ingat saya</label></div>
        <a href="{{ route('password.lupa') }}" class="fw-semibold">Lupa kata sandi?</a>
    </div>
    <button class="btn btn-primary w-100">Masuk</button>
</form>
@endsection
