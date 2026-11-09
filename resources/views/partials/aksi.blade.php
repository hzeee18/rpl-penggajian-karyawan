{{-- Tombol ikon kolom Aksi: lihat / ubah / hapus. Param opsional: $lihat, $ubah, $hapus (url), $pesanHapus --}}
<div class="d-inline-flex gap-2">
    @isset($lihat)<a href="{{ $lihat }}" class="btn btn-light btn-icon" title="Lihat"><i data-lucide="eye"></i></a>@endisset
    @isset($ubah)<a href="{{ $ubah }}" class="btn btn-light btn-icon" title="Ubah"><i data-lucide="pencil"></i></a>@endisset
    @isset($hapus)
        <form method="post" action="{{ $hapus }}" data-confirm="{{ $pesanHapus ?? 'Yakin hapus data ini? Tindakan ini tidak bisa dibatalkan.' }}">
            @csrf @method('delete')
            <button class="btn btn-outline-danger btn-icon" title="Hapus"><i data-lucide="trash-2"></i></button>
        </form>
    @endisset
</div>
