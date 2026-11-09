@extends('layouts.app')
@section('title', 'Periode')
@section('breadcrumb', 'Master Data / Periode')
@section('content')
<x-page-header judul="Periode" sub="Rentang tanggal dan hari kerja untuk perhitungan gaji.">
    <a href="{{ route('master-payroll.index', ['baru' => 1]) }}" class="btn btn-primary">Tambah Periode</a>
</x-page-header>

<div class="md-grid">
    <div class="card">
        <x-cari placeholder="Cari periode..." />
        <div class="table-responsive">
            <table class="table table-hover">
                <thead><tr><th>Periode</th><th>Status</th><th>Dibuat oleh</th><th class="text-center">Hari kerja</th><th>Aksi</th></tr></thead>
                <tbody>
                @forelse ($periode as $p)
                    @php $st = $p->payroll?->status; @endphp
                    <tr class="{{ $ubah->id === $p->id ? 'terpilih' : '' }}">
                        <td class="fw-semibold">{{ \Carbon\Carbon::parse($p->periode . '-01')->translatedFormat('M y') }}</td>
                        <td>
                            @if ($st === 'final')<span class="badge badge-soft-success">Final</span>
                            @elseif ($st === 'menunggu')<span class="badge badge-soft-warning">Approval</span>
                            @elseif ($st === 'ditolak')<span class="badge badge-soft-danger">Ditolak</span>
                            @else<span class="badge badge-soft-neutral">Draft</span>@endif
                        </td>
                        <td>{!! orang($p->pembuat?->namaTampil() ?? 'Sistem', ($p->pembuat?->role?->nama ?? 'HR') . ' · ' . $p->created_at->translatedFormat('j M')) !!}</td>
                        <td class="text-center">{{ $p->hari_kerja }}</td>
                        <td class="text-nowrap">@include('partials.aksi', array_filter(['ubah' => route('master-payroll.index', ['ubah' => $p->id]), 'hapus' => $p->payroll ? null : route('master-payroll.destroy', $p)]))</td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-empty ikon="calendar" judul="Belum ada periode" /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($ubah)
        @php $kunci = $ubah->payroll && ! $ubah->payroll->bisaDigenerate(); @endphp
        <x-panel :judul="'Periode ' . nama_periode($ubah->periode)" :sub="tgl($ubah->tgl_mulai, 'j M') . '–' . tgl($ubah->tgl_selesai, 'j M Y')"
                 :action="$ubah->exists ? route('master-payroll.update', $ubah) : route('master-payroll.store')" :method="$ubah->exists ? 'put' : 'post'">
            <input type="hidden" name="periode" value="{{ $ubah->periode }}">
            @unless ($ubah->exists)
                <div class="mb-field"><label class="form-label">Periode</label><input type="month" name="periode" value="{{ old('periode', $ubah->periode) }}" class="form-control" required></div>
            @endunless
            <x-rentang class="mb-field" label="Rentang periode" awal="tgl_mulai" akhir="tgl_selesai" :nilai-awal="$ubah->tgl_mulai" :nilai-akhir="$ubah->tgl_selesai" :disabled="$kunci" :nonaktif="$terpakai" required />
            <div class="form-text mt-n2 mb-field">Tanggal yang sudah masuk periode lain dicoret di kalender dan tidak bisa dipilih.</div>
            <div class="mb-field"><label class="form-label">Hari kerja</label>
                <div class="input-group"><input type="number" name="hari_kerja" min="1" max="31" value="{{ old('hari_kerja', $ubah->hari_kerja) }}" class="form-control" @disabled($kunci) required><span class="input-group-text bg-white">hari</span></div></div>
            @if ($kunci)
                <x-catatan tipe="info">Payroll periode ini sudah {{ $ubah->payroll->status === 'final' ? 'final' : 'diajukan' }}, pengaturannya dikunci.</x-catatan>
            @else
                <x-catatan>Hari kerja jadi variabel <b>HK</b> di rumus komponen gaji (mis. potongan alfa GP / HK * ALFA). Pengali lembur sekarang diatur di rumus komponen Lembur (LB).</x-catatan>
                <div class="tombol"><a href="{{ route('master-payroll.index') }}" class="btn btn-light">Batal</a><button class="btn btn-primary">Simpan</button></div>
            @endif
        </x-panel>
    @endif
</div>
@endsection
