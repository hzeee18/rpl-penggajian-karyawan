{{-- Panel "Kontrak Baru" / "Revisi Kontrak" (Figma). Param: $kontrak, $karyawan, $komponen, $terpasang --}}
@php
    $baru = ! $kontrak->exists;
    $perpanjang = $baru && $kontrak->kontrak_sebelumnya_id;
    $lamaK = $perpanjang ? ($lama ?? $kontrak->sebelumnya) : null;
    $kembaliKe = route('kontrak.index', array_filter(['q' => request('q'), 'status' => request('status'), 'page' => request('page')]));
@endphp
<x-panel :judul="$perpanjang ? 'Perbarui Kontrak' : ($baru ? 'Kontrak Karyawan Baru' : 'Revisi Kontrak')"
         :sub="$baru && ! $perpanjang ? 'Untuk karyawan yang belum punya kontrak aktif' : $kontrak->karyawan->nama . ' · ' . $kontrak->karyawan->departemen?->nama"
         :action="$baru ? route('kontrak.store') : route('kontrak.update', $kontrak)" :method="$baru ? 'post' : 'put'">
    @if ($perpanjang)
        <input type="hidden" name="kontrak_sebelumnya_id" value="{{ $kontrak->kontrak_sebelumnya_id }}">
        <input type="hidden" name="karyawan_id" value="{{ $kontrak->karyawan_id }}">
        <div class="mb-field"><x-catatan tipe="info">Melanjutkan <b>{{ $lamaK->kode }}</b> yang berakhir {{ tgl($lamaK->tgl_akhir) }}. Kontrak baru dapat nomor baru otomatis dan isiannya sudah disalin dari kontrak lama, tinggal sesuaikan.</x-catatan></div>
    @endif
    @if (! $baru && ($tolak = $kontrak->approvals->firstWhere('status', 'ditolak')))
        <div class="mb-field"><x-catatan tipe="danger">Ditolak: {{ $tolak->catatan }}</x-catatan></div>
    @endif
    @unless ($perpanjang)
    <div class="mb-field"><label class="form-label">Karyawan</label>
        <select name="karyawan_id" class="form-select" required><option value="">Pilih karyawan</option>@foreach ($karyawan as $k)<option value="{{ $k->id }}" @selected(old('karyawan_id', $kontrak->karyawan_id) == $k->id)>{{ $k->nama }} · {{ $k->departemen?->nama }}</option>@endforeach</select>
        @if ($baru && $karyawan->isEmpty())<div class="form-text">Semua karyawan aktif sudah punya kontrak. Untuk melanjutkan kontrak yang mau habis, pakai tombol Perbarui di tabel.</div>@endif</div>
    @endunless
    <div class="mb-field"><label class="form-label">Jenis &amp; periode</label>
        <select name="jenis" id="jenisKontrak" class="form-select mb-2"><option value="PKWT" @selected(old('jenis', $kontrak->jenis) === 'PKWT')>PKWT (waktu tertentu)</option><option value="PKWTT" @selected(old('jenis', $kontrak->jenis) === 'PKWTT')>PKWTT (tetap)</option></select>
        <div class="d-flex gap-2 align-items-center">
            <input type="date" name="tgl_mulai" value="{{ old('tgl_mulai', $kontrak->tgl_mulai?->toDateString()) }}" class="form-control" required>
            <span class="akhir-kontrak text-muted">–</span>
            <input type="date" name="tgl_akhir" value="{{ old('tgl_akhir', $kontrak->tgl_akhir?->toDateString()) }}" class="form-control akhir-kontrak">
        </div>
    </div>
    <div class="mb-field"><label class="form-label">Gaji pokok</label>
        <div class="input-group"><span class="input-group-text bg-white">Rp</span><input type="number" min="0" data-rupiah name="gaji_pokok" value="{{ old('gaji_pokok', $kontrak->gaji_pokok ? (int) $kontrak->gaji_pokok : '') }}" class="form-control" placeholder="4800000" required></div></div>
    <div class="mb-field"><label class="form-label">Komponen gaji</label>
        <div class="multi-chip">
            @foreach ($komponen as $c)
                @php $kk = $terpasang[$c->id] ?? null; @endphp
                <label><input type="checkbox" name="komponen[{{ $c->id }}][pakai]" value="1" @checked(old("komponen.{$c->id}.pakai", $baru ? $c->status_berlaku === 'aktif' : (bool) $kk))><span>{{ $c->nama }}</span></label>
                <input type="hidden" name="komponen[{{ $c->id }}][tipe_nilai]" value="{{ $kk?->tipe_nilai ?? $c->tipe_nilai }}">
                <input type="hidden" name="komponen[{{ $c->id }}][nilai]" value="{{ ($kk?->nilai ?? $c->nilai_default) + 0 }}">
            @endforeach
        </div>
        <div class="form-text">Nilai mengikuti master Komponen Gaji. Gaji pokok, potongan alfa, dan PPh21 otomatis.</div>
    </div>
    <x-catatan>Setelah disimpan, kontrak dikirim ke Manager divisi karyawan (sesuai approval mapping) untuk disetujui.</x-catatan>
    <div class="tombol"><a href="{{ $kembaliKe }}" class="btn btn-light">Batal</a><button class="btn btn-primary">Simpan &amp; Ajukan</button></div>
</x-panel>
@push('scripts')
<script>
    (() => { const j = document.getElementById('jenisKontrak'); const atur = () => document.querySelectorAll('.akhir-kontrak').forEach(e => e.style.display = j.value === 'PKWTT' ? 'none' : ''); j.addEventListener('change', atur); atur(); })();
</script>
@endpush
