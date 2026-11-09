<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; }
    .kop { border-bottom: 2px solid #2563eb; padding-bottom: 8px; margin-bottom: 12px; }
    .kop h1 { margin: 0; font-size: 18px; color: #2563eb; }
    .kop .sub { color: #666; }
    h2 { font-size: 14px; margin: 0 0 10px; text-align: center; letter-spacing: 1px; }
    table { width: 100%; border-collapse: collapse; }
    .info td { padding: 2px 0; }
    .rincian { margin-top: 12px; }
    .rincian th { background: #f1f5f9; text-align: left; padding: 6px; border: 1px solid #ddd; }
    .rincian td { padding: 5px 6px; border: 1px solid #ddd; }
    .r { text-align: right; }
    .b { font-weight: bold; }
    .thp { margin-top: 12px; background: #eff6ff; border: 1px solid #2563eb; padding: 10px; font-size: 14px; }
    .ttd { margin-top: 40px; }
    .muted { color: #777; font-size: 9px; }
</style>
</head>
<body>
@php
    $penambah = $details->filter(fn ($d) => $d->component->jenis === 'penambah')->values();
    $pengurang = $details->filter(fn ($d) => $d->component->jenis === 'pengurang')->values();
    $baris = max($penambah->count(), $pengurang->count());
    $satuan = fn ($d) => match ($d->component->dasar_bayar) { 'hadir', 'alfa' => ' (' . ($d->jumlah + 0) . ' hari)', 'lembur' => ' (' . ($d->jumlah + 0) . ' jam)', default => '' };
@endphp
<div class="kop">
    <h1>{{ config('app.name') }}</h1>
    <div class="sub">PT Contoh Sejahtera · Jl. Sudirman No. 1, Jakarta</div>
</div>
<h2>SLIP GAJI · {{ strtoupper($slip->payroll->namaPeriode()) }}</h2>
<table class="info">
    <tr><td width="18%">NIK</td><td width="32%">: {{ $slip->karyawan->nik }}</td><td width="18%">Departemen</td><td>: {{ $slip->karyawan->departemen?->nama }}</td></tr>
    <tr><td>Nama</td><td>: {{ $slip->karyawan->nama }}</td><td>Jabatan</td><td>: {{ $slip->karyawan->jabatan }}</td></tr>
    <tr><td>Status PTKP</td><td>: {{ $slip->karyawan->pajak?->status_ptkp }}</td><td>Rekening</td><td>: {{ $slip->karyawan->nama_bank }} {{ $slip->karyawan->no_rekening }}</td></tr>
</table>
<table class="rincian">
    <tr><th colspan="2">Penerimaan</th><th colspan="2">Potongan</th></tr>
    @for ($i = 0; $i < $baris; $i++)
        <tr>
            <td>{{ isset($penambah[$i]) ? $penambah[$i]->component->nama . $satuan($penambah[$i]) : '' }}</td>
            <td class="r">{{ isset($penambah[$i]) ? rupiah($penambah[$i]->nominal) : '' }}</td>
            <td>{{ isset($pengurang[$i]) ? $pengurang[$i]->component->nama . $satuan($pengurang[$i]) : '' }}</td>
            <td class="r">{{ isset($pengurang[$i]) ? rupiah($pengurang[$i]->nominal) : '' }}</td>
        </tr>
    @endfor
    <tr class="b"><td>Total bruto</td><td class="r">{{ rupiah($slip->total_bruto) }}</td><td>Total potongan</td><td class="r">{{ rupiah($slip->total_potongan) }}</td></tr>
</table>
<div class="thp"><table><tr><td class="b">TAKE HOME PAY</td><td class="r b">{{ rupiah($slip->thp) }}</td></tr></table></div>
<table class="ttd"><tr><td width="60%"></td><td>Jakarta, {{ tgl(now()) }}<br>Bagian HR<br><br><br><br>( ____________________ )</td></tr></table>
<p class="muted">Dokumen ini dibuat otomatis oleh sistem dan sah tanpa tanda tangan basah. Dicetak {{ now()->translatedFormat('j F Y H:i') }}.</p>
</body>
</html>
