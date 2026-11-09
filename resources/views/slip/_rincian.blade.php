{{-- Rincian penambah & pengurang. Param: $details (koleksi PayrollDetail) --}}
@php
    $penambah = $details->filter(fn ($d) => $d->component->jenis === 'penambah');
    $pengurang = $details->filter(fn ($d) => $d->component->jenis === 'pengurang');
    $satuan = fn ($d) => match ($d->component->dasar_bayar) { 'hadir', 'alfa' => ($d->jumlah + 0) . ' hari', 'lembur' => ($d->jumlah + 0) . ' jam', default => '' };
@endphp
<div class="row g-0">
    <div class="col-md-6 border-end">
        <table class="table table-sm mb-0">
            <thead><tr><th colspan="2" class="text-success">Penerimaan</th></tr></thead>
            @foreach ($penambah as $d)<tr><td>{{ $d->component->nama }} <span class="text-muted small">{{ $satuan($d) }}</span></td><td class="text-uang">{{ rupiah($d->nominal) }}</td></tr>@endforeach
            <tr class="fw-semibold"><td>Total bruto</td><td class="text-uang">{{ rupiah($penambah->sum('nominal')) }}</td></tr>
        </table>
    </div>
    <div class="col-md-6">
        <table class="table table-sm mb-0">
            <thead><tr><th colspan="2" class="text-danger">Potongan</th></tr></thead>
            @forelse ($pengurang as $d)<tr><td>{{ $d->component->nama }} <span class="text-muted small">{{ $satuan($d) }}</span></td><td class="text-uang">{{ rupiah($d->nominal) }}</td></tr>@empty<tr><td colspan="2" class="text-muted">-</td></tr>@endforelse
            <tr class="fw-semibold"><td>Total potongan</td><td class="text-uang">{{ rupiah($pengurang->sum('nominal')) }}</td></tr>
        </table>
    </div>
</div>
<div class="d-flex justify-content-between align-items-center px-3 py-3 bg-light border-top">
    <span class="fw-semibold">Take home pay</span>
    <span class="h4 mb-0 text-primary">{{ rupiah($penambah->sum('nominal') - $pengurang->sum('nominal')) }}</span>
</div>
