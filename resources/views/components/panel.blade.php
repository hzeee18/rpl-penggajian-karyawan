@props(['judul', 'sub' => null, 'action' => null, 'method' => 'post', 'buka' => null, 'id' => 'drawer'])
{{-- Drawer kanan (geser dari kanan + latar gelap). Terbuka otomatis kalau URL berisi ubah/lihat/baru atau ada error validasi. --}}
@php $terbuka = $buka ?? (request()->hasAny(['ubah', 'lihat', 'baru', 'perbarui']) || $errors->any()); @endphp
<div id="{{ $id }}" {{ $attributes->except('enctype')->merge(['class' => 'card panel drawer' . ($terbuka ? ' buka' : '')]) }} role="dialog" aria-modal="true">
    <div class="card-header d-flex align-items-start gap-2">
        <div class="me-auto"><div class="judul-panel">{{ $judul }}</div>@if ($sub)<span class="sub">{{ $sub }}</span>@endif</div>
        <button type="button" class="btn btn-light btn-icon" data-tutup-drawer aria-label="Tutup"><i data-lucide="x"></i></button>
    </div>
    @if ($action)
        <form method="post" action="{{ $action }}" class="card-body" {{ $attributes->only('enctype') }}>
            @csrf @if (strtolower($method) !== 'post') @method($method) @endif
            {{ $slot }}
        </form>
    @else
        <div class="card-body">{{ $slot }}</div>
    @endif
</div>
