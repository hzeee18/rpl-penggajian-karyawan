@props(['ikon' => 'inbox', 'judul' => 'Belum ada data', 'pesan' => null])
<div class="empty">
    <i data-lucide="{{ $ikon }}"></i>
    <span class="judul-empty">{{ $judul }}</span>
    @if ($pesan)<div class="small">{{ $pesan }}</div>@endif
    @if (trim($slot))<div class="mt-3">{{ $slot }}</div>@endif
</div>
