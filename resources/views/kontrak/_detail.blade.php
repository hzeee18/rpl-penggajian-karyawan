{{-- Rincian kontrak (dipakai di halaman kontrak, approval, profil). Param: $kontrak. Nominal disembunyikan untuk Manager divisi (kecuali kontraknya sendiri). --}}
@php $lihatGaji = auth()->user()->bolehLihatGaji() || $kontrak->karyawan_id === auth()->user()->karyawan_id; @endphp
<dl class="row info mb-0">
    <dt class="col-sm-4">Karyawan</dt><dd class="col-sm-8">{{ $kontrak->karyawan->nama }} ({{ $kontrak->karyawan->nik }})</dd>
    <dt class="col-sm-4">Departemen / jabatan</dt><dd class="col-sm-8">{{ $kontrak->karyawan->departemen?->nama }} / {{ $kontrak->karyawan->jabatan }}</dd>
    <dt class="col-sm-4">Jenis kontrak</dt><dd class="col-sm-8">{{ $kontrak->jenis === 'PKWT' ? 'PKWT (waktu tertentu)' : 'PKWTT (tetap)' }}</dd>
    <dt class="col-sm-4">Masa berlaku</dt><dd class="col-sm-8">{{ tgl($kontrak->tgl_mulai) }} – {{ $kontrak->tgl_akhir ? tgl($kontrak->tgl_akhir) : 'tidak terbatas' }}</dd>
    <dt class="col-sm-4">Gaji pokok</dt><dd class="col-sm-8 fw-semibold">{{ $lihatGaji ? rupiah($kontrak->gaji_pokok) : 'Rahasia (diatur HR)' }}</dd>
</dl>
<table class="table table-sm mt-2 mb-0">
    <thead><tr><th>Komponen gaji</th><th>Jenis</th>@if ($lihatGaji)<th>Nilai</th>@endif</tr></thead>
    <tbody>
    @forelse ($kontrak->komponen as $kk)
        <tr>
            <td>{{ $kk->component->nama }}</td>
            <td>{{ ucfirst($kk->component->jenis) }}</td>
            @if ($lihatGaji)<td><code class="rumus">{{ $kk->component->rumus ?: $kk->component->nilaiRingkas() }}</code></td>@endif
        </tr>
    @empty
        <tr><td colspan="3" class="text-muted">Tidak ada komponen tambahan</td></tr>
    @endforelse
    </tbody>
</table>
