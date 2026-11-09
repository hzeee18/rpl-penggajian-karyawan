{{-- Toast di kanan atas (komponen Toast di Figma). Sukses/info hilang sendiri 4 detik, error tetap sampai ditutup. --}}
@php
    $toasts = [];
    if (session('sukses')) $toasts[] = ['success', 'Berhasil', session('sukses'), 'check'];
    if (session('info')) $toasts[] = ['info', 'Info', session('info'), 'info'];
    if (session('gagal')) $toasts[] = ['error', 'Gagal', session('gagal'), 'x'];
    if ($errors->any() && empty($tanpaError)) $toasts[] = ['warning', 'Periksa lagi isian', $errors->all(), 'triangle-alert'];
@endphp
<div class="toast-wrap">
    @foreach ($toasts as [$tipe, $judul, $pesan, $ikon])
        <div class="toast-item {{ $tipe }}" data-auto="{{ in_array($tipe, ['success', 'info']) ? 1 : 0 }}" role="alert">
            <span class="ikon"><i data-lucide="{{ $ikon }}"></i></span>
            <div>
                <div class="judul">{{ $judul }}</div>
                <div class="pesan">
                    @if (is_array($pesan))
                        @foreach ($pesan as $p)<div>{{ $p }}</div>@endforeach
                    @else
                        {{ $pesan }}
                    @endif
                </div>
            </div>
            <button type="button" class="tutup" onclick="this.closest('.toast-item').remove()" aria-label="Tutup"><i data-lucide="x"></i></button>
        </div>
    @endforeach
</div>
<script>
    document.querySelectorAll('.toast-item[data-auto="1"]').forEach(t => setTimeout(() => t.remove(), 4000));
</script>
