{{-- Indikator langkah proses payroll --}}
<div class="d-flex gap-2 mb-4 flex-wrap">
    @foreach (['Cek rekap', 'Generate', 'Ajukan approval', 'Final & slip terbit'] as $i => $l)
        <span class="badge {{ $i + 1 < $aktif ? 'badge-soft-success' : ($i + 1 === $aktif ? 'badge-soft-primary' : 'badge-soft-neutral') }} px-3 py-2">{{ $i + 1 }}. {{ $l }}</span>
    @endforeach
</div>
