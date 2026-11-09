<!doctype html>
<html lang="id"><head><meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; }
    .kop { border-bottom: 2px solid #1b6fa3; padding-bottom: 8px; margin-bottom: 14px; }
    .kop h1 { margin: 0; font-size: 17px; color: #1b6fa3; }
    h2 { text-align: center; font-size: 14px; letter-spacing: 1px; margin: 0 0 4px; }
    .no { text-align: center; color: #666; margin-bottom: 16px; }
    table { width: 100%; border-collapse: collapse; }
    td { padding: 4px 0; vertical-align: top; }
    .k { width: 32%; color: #555; }
    .tb th, .tb td { border: 1px solid #ccc; padding: 5px 6px; } .tb th { background: #f1f5f9; text-align: left; }
    .ttd td { padding-top: 50px; text-align: center; }
</style></head>
<body>
<div class="kop"><h1>Universitas Indraprasta PGRI · Aplikasi Penggajian Karyawan</h1></div>
<h2>KONTRAK KERJA {{ $kontrak->jenis === 'PKWT' ? 'WAKTU TERTENTU (PKWT)' : 'WAKTU TIDAK TERTENTU (PKWTT)' }}</h2>
<div class="no">No. {{ $kontrak->kode }}</div>
<table>
    <tr><td class="k">Nama</td><td>: {{ $kontrak->karyawan->nama }}</td></tr>
    <tr><td class="k">NIK</td><td>: {{ $kontrak->karyawan->nik }}</td></tr>
    <tr><td class="k">Jabatan / Departemen</td><td>: {{ $kontrak->karyawan->jabatan }} / {{ $kontrak->karyawan->departemen?->nama }}</td></tr>
    <tr><td class="k">Masa berlaku</td><td>: {{ tgl($kontrak->tgl_mulai) }} s/d {{ $kontrak->tgl_akhir ? tgl($kontrak->tgl_akhir) : 'tidak terbatas' }}</td></tr>
    <tr><td class="k">Gaji pokok</td><td>: {{ rupiah($kontrak->gaji_pokok) }} per bulan</td></tr>
    <tr><td class="k">Status</td><td>: {{ ucfirst($kontrak->status_approval) }}</td></tr>
</table>
<p style="margin-top:14px"><b>Komponen gaji</b></p>
<table class="tb">
    <tr><th>Komponen</th><th>Jenis</th><th>Keterangan</th></tr>
    @forelse ($kontrak->komponen as $kk)
        <tr><td>{{ $kk->component->nama }}</td><td>{{ ucfirst($kk->component->jenis) }}</td><td>{{ $kk->component->keterangan() }}</td></tr>
    @empty
        <tr><td colspan="3">-</td></tr>
    @endforelse
</table>
<table class="ttd"><tr><td>Karyawan<br><br><br><br>( {{ $kontrak->karyawan->nama }} )</td><td>Disetujui<br><br><br><br>( {{ $kontrak->approvals->firstWhere('status', 'disetujui')?->approver?->namaTampil() ?? '____________' }} )</td></tr></table>
<p style="color:#777;font-size:9px;margin-top:30px">Dicetak dari sistem {{ now()->translatedFormat('j F Y H:i') }}.</p>
</body></html>
