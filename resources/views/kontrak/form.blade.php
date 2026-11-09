@extends('layouts.app')
@section('title', $kontrak->exists ? 'Revisi kontrak' : 'Buat kontrak')
@section('breadcrumb', 'Karyawan / Kontrak')
@section('content')
<x-page-header :judul="$kontrak->exists ? 'Revisi kontrak' : 'Buat kontrak'" sub="Setelah disimpan, kontrak dikirim ke Manager divisi untuk disetujui." />
<form method="post" action="{{ $kontrak->exists ? route('kontrak.update', $kontrak) : route('kontrak.store') }}" class="card" style="max-width:900px">
    @csrf @if ($kontrak->exists) @method('put') @endif
    <div class="card-body">
        @if ($kontrak->status_approval === 'ditolak' && ($tolak = $kontrak->approvals->firstWhere('status', 'ditolak')))
            <div class="alert alert-warning"><strong>Ditolak:</strong> {{ $tolak->catatan }}</div>
        @endif
        <div class="row">
            <div class="col-md-6 mb-3"><label class="form-label">Karyawan</label>
                <select name="karyawan_id" class="form-select" required><option value="">Pilih karyawan</option>
                    @foreach ($karyawan as $k)<option value="{{ $k->id }}" @selected(old('karyawan_id', $kontrak->karyawan_id) == $k->id)>{{ $k->nik }} · {{ $k->nama }}</option>@endforeach</select></div>
            <div class="col-md-6 mb-3"><label class="form-label">Jenis</label>
                <select name="jenis" id="jenis" class="form-select"><option value="PKWT" @selected(old('jenis', $kontrak->jenis) === 'PKWT')>PKWT (waktu tertentu)</option><option value="PKWTT" @selected(old('jenis', $kontrak->jenis) === 'PKWTT')>PKWTT (tetap)</option></select></div>
            <div class="col-md-4 mb-3"><label class="form-label">Tanggal mulai</label><input type="date" name="tgl_mulai" value="{{ old('tgl_mulai', $kontrak->tgl_mulai?->toDateString()) }}" class="form-control" required></div>
            <div class="col-md-4 mb-3" id="wrapAkhir"><label class="form-label">Tanggal akhir</label><input type="date" name="tgl_akhir" value="{{ old('tgl_akhir', $kontrak->tgl_akhir?->toDateString()) }}" class="form-control"></div>
            <div class="col-md-4 mb-3"><label class="form-label">Gaji pokok (Rp)</label><input type="number" min="0" data-rupiah name="gaji_pokok" value="{{ old('gaji_pokok', $kontrak->gaji_pokok) }}" class="form-control" required></div>
        </div>

        <h6 class="mt-2">Komponen gaji di kontrak</h6>
        <p class="small text-muted">Gaji pokok, potongan alfa, dan PPh 21 dihitung otomatis. Centang komponen lain yang berlaku. Untuk lembur, isi 0 kalau pakai rumus 1/173.</p>
        <table class="table table-sm align-middle">
            <thead><tr><th style="width:40px"></th><th>Komponen</th><th>Tipe</th><th style="width:180px">Nilai</th></tr></thead>
            <tbody>
            @foreach ($komponen as $c)
                @php $kk = $terpasang[$c->id] ?? null; $o = old("komponen.{$c->id}"); @endphp
                <tr>
                    <td><input type="checkbox" class="form-check-input" name="komponen[{{ $c->id }}][pakai]" value="1" @checked($o ? ! empty($o['pakai']) : (bool) $kk)></td>
                    <td>{{ $c->nama }} <span class="badge text-bg-{{ $c->jenis === 'penambah' ? 'success' : 'danger' }}">{{ $c->jenis }}</span><div class="small text-muted">{{ \App\Models\PayslipComponent::DASAR_BAYAR[$c->dasar_bayar] }}</div></td>
                    <td><select name="komponen[{{ $c->id }}][tipe_nilai]" class="form-select form-select-sm">@foreach (['nominal' => 'Nominal', 'persen' => '% gaji pokok'] as $v => $l)<option value="{{ $v }}" @selected(($o['tipe_nilai'] ?? $kk?->tipe_nilai ?? $c->tipe_nilai) === $v)>{{ $l }}</option>@endforeach</select></td>
                    <td><input type="number" step="0.01" min="0" name="komponen[{{ $c->id }}][nilai]" value="{{ $o['nilai'] ?? ($kk?->nilai ?? $c->nilai_default) + 0 }}" class="form-control form-control-sm"></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white d-flex justify-content-between align-items-center">
        <span class="small text-muted"><i data-lucide="info"></i> Setelah disimpan, kontrak otomatis diajukan ke manager divisi karyawan.</span>
        <span><a href="{{ url()->previous() }}" class="btn btn-light">Batal</a> <button class="btn btn-primary">Simpan & ajukan</button></span>
    </div>
</form>
@push('scripts')
<script>
    const jenis = document.getElementById('jenis'), akhir = document.getElementById('wrapAkhir');
    const atur = () => akhir.style.display = jenis.value === 'PKWTT' ? 'none' : '';
    jenis.addEventListener('change', atur); atur();
</script>
@endpush
@endsection
