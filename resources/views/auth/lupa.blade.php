@extends('layouts.auth')
@section('title', 'Lupa kata sandi')
@section('content')
@php $admin = \App\Models\User::with('karyawan.departemen')->whereHas('role', fn ($q) => $q->where('nama', 'Admin'))->where('is_active', true)->first(); @endphp
<h1>Lupa kata sandi?</h1>
<p class="lead-sub mb-0">Demi keamanan, kata sandi hanya bisa direset oleh Admin. Hubungi Admin dengan menyebutkan username atau email kamu.</p>
@if ($admin)
    <div class="admin-kontak">
        <span class="avatar" style="background:#1b6fa3;color:#fff">{{ inisial($admin->namaTampil()) }}</span>
        <div><div class="fw-semibold">{{ $admin->namaTampil() }} · Admin</div><div class="small text-secondary">{{ $admin->karyawan?->departemen?->nama ? 'Departemen ' . $admin->karyawan->departemen->nama : '' }}{{ $admin->email }}</div></div>
    </div>
@endif
<p class="small text-secondary mb-4">Setelah direset, kamu akan dapat kata sandi sementara dari Admin. Ganti kata sandi itu di menu profil setelah login.</p>
<a href="{{ route('login') }}" class="btn btn-primary w-100">Kembali ke Login</a>
@endsection
