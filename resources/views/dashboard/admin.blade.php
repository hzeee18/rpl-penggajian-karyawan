@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
<x-page-header judul="Dashboard Admin" sub="Pengguna, role, menu, dan hak akses">
    <a href="{{ route('users.create') }}" class="btn btn-primary"><i data-lucide="plus"></i> Tambah User</a>
</x-page-header>

@include('dashboard._stat')

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header">Log Aktivitas Terbaru</div>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Waktu</th><th>Pengguna</th><th>Aktivitas</th><th>Modul</th></tr></thead>
                    <tbody>
                    @forelse ($log as $l)
                        <tr>
                            <td class="text-nowrap">{{ $l->created_at->translatedFormat('j M H.i') }}</td>
                            <td>{!! orang($l->user?->namaTampil() ?? '-', $l->user?->role?->nama) !!}</td>
                            <td>{{ $l->aktivitas }}</td>
                            <td><span class="badge badge-soft-neutral">{{ $l->modul }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="4"><x-empty ikon="history" judul="Belum ada aktivitas" /></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header">Perlu Perhatian</div>
            <div class="card-body">
                @foreach ($perhatian as [$judul, $ket, $badge, $warna, $link])
                    <a href="{{ $link }}" class="d-flex justify-content-between align-items-center mb-3 text-reset">
                        <div><div class="fw-semibold">{{ $judul }}</div><div class="small text-muted">{{ $ket }}</div></div>
                        <span class="badge badge-soft-{{ $warna }} text-nowrap">{{ $badge }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    </div>
</div>
@endsection
