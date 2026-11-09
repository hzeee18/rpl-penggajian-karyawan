@extends('layouts.app')
@section('title', 'Import Excel')
@section('breadcrumb', 'Absensi / Import Excel')
@section('content')
<x-page-header judul="Import Excel" sub="Upload data karyawan, kontrak, absensi, cuti, atau lembur pakai template.">
    <div class="dropdown">
        <button class="btn btn-light" data-bs-toggle="dropdown"><i data-lucide="file-down"></i> Unduh Template</button>
        <div class="dropdown-menu dropdown-menu-end">
            @foreach ($template as $k => $t)<a class="dropdown-item" href="{{ route('import.template', $k) }}">{{ $t['judul'] }}</a>@endforeach
        </div>
    </div>
    <a href="{{ route('import.index', ['baru' => 1]) }}" class="btn btn-primary"><i data-lucide="upload"></i> Upload File</a>
</x-page-header>

<div class="md-grid">
    <div class="card">
        <x-cari placeholder="Cari nama file..." />
        <div class="table-responsive">
            <table class="table table-hover">
                <thead><tr><th>No.</th><th>Status</th><th>File / diupload oleh</th><th class="text-center">Baris</th><th>Aksi</th></tr></thead>
                <tbody>
                @forelse ($riwayat as $r)
                    <tr class="{{ $lihat?->id === $r->id ? 'terpilih' : '' }}">
                        <td class="kode">IM-{{ str_pad($r->id, 2, '0', STR_PAD_LEFT) }}</td>
                        <td><span class="badge {{ $r->status === 'berhasil' ? 'badge-soft-success' : 'badge-soft-danger' }}">{{ $r->status === 'berhasil' ? 'Berhasil' : 'Ada error' }}</span></td>
                        <td>{!! orang($r->nama_file, ($r->user?->namaTampil() ?? '-') . ' · ' . $r->created_at->translatedFormat('j M'), 'avatar-sm', $r->user?->namaTampil()) !!}</td>
                        <td class="text-center">{{ $r->status === 'berhasil' ? $r->baris : $r->baris_error . ' / ' . $r->baris }}</td>
                        <td><a href="{{ route('import.index', ['lihat' => $r->id]) }}" class="btn btn-light btn-icon" title="Lihat"><i data-lucide="eye"></i></a></td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-empty ikon="file-spreadsheet" judul="Belum ada file yang di-import" pesan="Unduh template, isi, lalu upload di panel kanan." /></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($riwayat->lastPage() > 1) @include('partials.halaman', ['data' => $riwayat, 'label' => 'file']) @endif
    </div>

    @if ($lihat)
        <x-panel :judul="$lihat->nama_file" :sub="$template[$lihat->jenis]['judul'] . ' · ' . $lihat->created_at->translatedFormat('j M Y H.i')">
            <div class="rinci"><span class="k">Status</span><span class="v">{{ $lihat->status === 'berhasil' ? 'Berhasil disimpan' : 'Ada error, tidak disimpan' }}</span></div>
            <div class="rinci"><span class="k">Jumlah baris</span><span class="v">{{ $lihat->baris }}</span></div>
            @if ($lihat->status !== 'berhasil')
                <div class="rinci mb-2"><span class="k">Baris error</span><span class="v text-danger">{{ $lihat->baris_error }}</span></div>
                <div class="notice notice-danger d-block small" style="max-height:220px;overflow:auto">@foreach (explode("\n", (string) $lihat->catatan) as $e)<div>{{ $e }}</div>@endforeach</div>
            @endif
            <div class="tombol"><a href="{{ route('import.index') }}" class="btn btn-primary">Upload file lain</a></div>
        </x-panel>
    @else
        <x-panel judul="Upload File" sub="Pilih jenis data dan file Excel" :action="route('import.upload')" enctype="multipart/form-data">
            <div class="mb-field"><label class="form-label">Jenis data</label>
                <select name="jenis" class="form-select">@foreach ($template as $k => $t)<option value="{{ $k }}" @selected(old('jenis', request('jenis')) === $k)>{{ $t['judul'] }}</option>@endforeach</select></div>
            <div class="mb-field"><label class="form-label">Periode</label>
                <select name="periode" class="form-select"><option value="">— Semua tanggal —</option>@foreach (range(0, 5) as $i)@php $pr = now()->subMonthsNoOverflow($i)->format('Y-m'); @endphp<option value="{{ $pr }}" @selected(old('periode') === $pr)>{{ nama_periode($pr) }}</option>@endforeach</select>
                <div class="form-text">Untuk absensi, cuti, dan lembur: tanggal di file harus masuk periode ini.</div></div>
            <div class="mb-field"><label class="form-label">File</label><input type="file" name="file" accept=".xlsx,.xls,.csv" class="form-control" required>
                <div class="form-text">Pakai template. Tanggal YYYY-MM-DD, jam HH:MM.</div></div>
            <x-catatan>Data hanya disimpan kalau semua baris valid. Baris yang salah ditampilkan beserta alasannya.</x-catatan>
            <div class="tombol"><a href="{{ route('import.index') }}" class="btn btn-light">Batal</a><button class="btn btn-primary">Upload &amp; Validasi</button></div>
        </x-panel>
    @endif
</div>
@endsection
