<!doctype html>
<html lang="id"><head><meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; }
    h1 { font-size: 15px; margin: 0; } .sub { color: #666; margin-bottom: 10px; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #f1f5f9; } th, td { border: 1px solid #ccc; padding: 4px 5px; }
    .r { text-align: right; } tfoot td { font-weight: bold; background: #f8fafc; }
</style></head>
<body>
<h1>Laporan Penggajian {{ $payroll->namaPeriode() }}</h1>
<div class="sub">{{ config('app.name') }} · Status: {{ $payroll->status }} · {{ $departemenId ? $departemen->firstWhere('id', $departemenId)?->nama : 'Semua departemen' }} · Dicetak {{ now()->translatedFormat('j F Y H:i') }}</div>
<table>
    <thead><tr><th>No</th><th>NIK</th><th>Nama</th><th>Departemen</th><th>Jabatan</th><th>Gaji pokok</th><th>Bruto</th><th>PPh 21</th><th>Potongan</th><th>Take home pay</th></tr></thead>
    <tbody>
    @foreach ($ringkasan as $i => $r)
        <tr><td>{{ $i + 1 }}</td><td>{{ $r->karyawan->nik }}</td><td>{{ $r->karyawan->nama }}</td><td>{{ $r->karyawan->departemen?->nama }}</td><td>{{ $r->karyawan->jabatan }}</td>
            <td class="r">{{ rupiah($r->gaji_pokok) }}</td><td class="r">{{ rupiah($r->bruto) }}</td><td class="r">{{ rupiah($r->pph21) }}</td><td class="r">{{ rupiah($r->potongan) }}</td><td class="r">{{ rupiah($r->thp) }}</td></tr>
    @endforeach
    </tbody>
    <tfoot><tr><td colspan="5">Total ({{ $ringkasan->count() }} karyawan)</td>
        <td class="r">{{ rupiah($ringkasan->sum('gaji_pokok')) }}</td><td class="r">{{ rupiah($ringkasan->sum('bruto')) }}</td><td class="r">{{ rupiah($ringkasan->sum('pph21')) }}</td><td class="r">{{ rupiah($ringkasan->sum('potongan')) }}</td><td class="r">{{ rupiah($ringkasan->sum('thp')) }}</td></tr></tfoot>
</table>
</body></html>
