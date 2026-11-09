{{-- Tombol hapus dengan konfirmasi. Param: $url, $label (opsional), $pesan (opsional) --}}
<form method="post" action="{{ $url }}" class="d-inline" data-confirm="{{ $pesan ?? 'Yakin hapus data ini? Tindakan ini tidak bisa dibatalkan.' }}">
    @csrf @method('delete')
    <button class="btn btn-outline-danger {{ isset($label) ? 'btn-sm' : 'btn-icon' }}" title="{{ $label ?? 'Hapus' }}"><i data-lucide="trash-2"></i>@isset($label) {{ $label }}@endisset</button>
</form>
