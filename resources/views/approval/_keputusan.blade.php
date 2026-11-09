{{-- Isi panel keputusan approval. Param: $pilih (Approval), $ref, $ringkasan --}}
@if ($pilih->jenis_ref === 'payroll')
    @php $tunj = $ringkasan->sum(fn ($r) => $r->rows->filter(fn ($d) => in_array($d->component->dasar_bayar, ['harian', 'hadir', 'bulanan', 'thr', 'rumus', 'semua']) && $d->component->jenis === 'penambah')->sum('nominal'));
         $lmb = $ringkasan->sum(fn ($r) => $r->rows->filter(fn ($d) => $d->component->dasar_bayar === 'lembur')->sum('nominal')); @endphp
    <div class="rinci"><span class="k">Total gaji pokok</span><span class="v">{{ rupiah($ringkasan->sum('gaji_pokok')) }}</span></div>
    <div class="rinci"><span class="k">Total tunjangan</span><span class="v">{{ rupiah($tunj) }}</span></div>
    <div class="rinci"><span class="k">Total lembur</span><span class="v">{{ rupiah($lmb) }}</span></div>
    <div class="rinci"><span class="k">Potongan &amp; PPh21</span><span class="v">{{ rupiah($ringkasan->sum('potongan')) }}</span></div>
    <div class="rinci total"><span class="k">Gaji bersih</span><span class="v">{{ rupiah($ringkasan->sum('thp')) }}</span></div>
@else
    <div class="rinci"><span class="k">Karyawan</span><span class="v">{{ $ref->karyawan->nama }}</span></div>
    <div class="rinci"><span class="k">Jenis</span><span class="v">{{ $ref->jenis }}</span></div>
    <div class="rinci"><span class="k">Periode</span><span class="v">{{ tgl($ref->tgl_mulai, 'j M Y') }} – {{ $ref->tgl_akhir ? tgl($ref->tgl_akhir, 'j M Y') : 'tetap' }}</span></div>
    <div class="rinci"><span class="k">Komponen</span><span class="v small">{{ $ref->komponen->map(fn ($k) => $k->component->nama)->join(', ') ?: '-' }}</span></div>
    @if (auth()->user()->bolehLihatGaji())<div class="rinci total"><span class="k">Gaji pokok</span><span class="v">{{ rupiah($ref->gaji_pokok) }}</span></div>@else<div class="rinci"><span class="k">Gaji pokok</span><span class="v text-muted">Rahasia (diatur HR)</span></div>@endif
@endif
@if ($pilih->status === 'menunggu')
    <form method="post" action="{{ route('approval.setujui', $pilih) }}" class="mt-3">
        @csrf
        <label class="form-label">Catatan</label>
        <input name="catatan" value="{{ old('catatan') }}" class="form-control mb-3" placeholder="Wajib diisi kalau ditolak">
        <x-catatan>{{ $pilih->jenis_ref === 'payroll' ? 'Setelah disetujui, payroll langsung final dan slip gaji semua karyawan terbit otomatis.' : 'Setelah disetujui, kontrak aktif dan kontrak lama karyawan ini dinonaktifkan.' }}</x-catatan>
        <div class="tombol">
            <button formaction="{{ route('approval.tolak', $pilih) }}" class="btn btn-danger">Tolak</button>
            <button class="btn btn-success">Setujui</button>
        </div>
    </form>
@else
    <div class="mt-3">{!! badge_status($pilih->status) !!} <span class="small text-secondary">pada {{ tgl($pilih->tgl_approval) }}</span></div>
    @if ($pilih->catatan)<div class="small text-muted mt-1">Catatan: {{ $pilih->catatan }}</div>@endif
@endif
