@extends('layouts.app')
@section('title', 'Notifikasi')
@section('breadcrumb', 'Notifikasi')
@section('content')
<x-page-header :judul="'Notifikasi'" sub="Kabar soal approval, lembur, dan slip gaji." />
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        Semua notifikasi
        <form method="post" action="{{ route('notifikasi.bacaSemua') }}">@csrf<button class="btn btn-sm btn-outline-secondary">Tandai semua dibaca</button></form>
    </div>
    <div class="list-group list-group-flush">
        @forelse ($notifikasi as $n)
            <a href="{{ route('notifikasi.buka', $n) }}" class="list-group-item list-group-item-action {{ $n->is_read ? '' : 'list-group-item-primary' }}">
                <div class="d-flex justify-content-between"><strong>{{ $n->judul }}</strong><small class="text-muted">{{ $n->created_at?->translatedFormat('j M Y H:i') }}</small></div>
                <div class="small">{{ $n->pesan }}</div>
            </a>
        @empty
            <div class="empty"><i data-lucide="bell-off"></i>Belum ada notifikasi</div>
        @endforelse
    </div>
</div>
<div class="mt-3">{{ $notifikasi->links() }}</div>
@endsection
