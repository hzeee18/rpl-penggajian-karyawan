@extends('layouts.app')
@section('title', 'Import CSV Payroll')
@section('breadcrumb', 'Payroll / Import CSV')
@section('content')
@php
    $u = auth()->user();
    $pembuat = \App\Models\User::whereIn('id', $batch->pluck('created_by')->filter()->unique())->get()->keyBy('id');
    $statusImport = fn ($b) => match (true) {
        $b->status === 'committed' => ['Berhasil', 'badge-soft-success'],
        $b->status === 'cancelled' => ['Dibatalkan', 'badge-soft-neutral'],
        $b->valid_rows !== $b->total_rows => ['Ada error', 'badge-soft-danger'],
        default => ['Siap disimpan', 'badge-soft-warning'],
    };
    $bukaForm = request()->has('baru') || $errors->any();
@endphp
<x-page-header judul="Import CSV" sub="Unggah absensi, leave, atau lembur pakai template. Data dicek dulu di halaman preview sebelum disimpan.">
    <div class="dropdown">
        <button class="btn btn-light dropdown-toggle" data-bs-toggle="dropdown"><i data-lucide="file-down"></i> Unduh Template</button>
        <div class="dropdown-menu dropdown-menu-end">
            @foreach ($jenis as $j)<a href="{{ route('pay.import.template', $j->slug()) }}" class="dropdown-item">Unduh template {{ strtolower($j->label()) }}</a>@endforeach
        </div>
    </div>
    @if ($u->bisa('pay-import', 'create'))<a href="{{ route('pay.import.index', ['baru' => 1]) }}" class="btn btn-primary"><i data-lucide="upload"></i> Upload File</a>@endif
</x-page-header>

<div class="md-grid">
    <div class="card"><div class="table-responsive"><table class="table table-hover">
        <thead><tr><th>No.</th><th>Jenis</th><th>Status</th><th>File / diupload oleh</th><th class="text-center">Baris valid</th><th class="text-center">Aksi</th></tr></thead>
        <tbody>
        @forelse ($batch as $b)
            @php [$lbl, $cls] = $statusImport($b); $oleh = $pembuat[$b->created_by] ?? null; @endphp
            <tr><td class="kode">IM-{{ $b->id }}</td>
                <td>{{ \App\Domain\Payroll\Import\JenisKehadiran::from($b->type)->label() }}</td>
                <td><span class="badge {{ $cls }}">{{ $lbl }}</span></td>
                <td>{!! orang($b->file_name, ($oleh?->namaTampil() ?? '-') . ' · ' . tgl($b->created_at, 'j M Y'), 'avatar-sm', $oleh?->namaTampil() ?? '?') !!}</td>
                <td class="text-center {{ $b->valid_rows !== $b->total_rows ? 'text-danger fw-semibold' : '' }}">{{ $b->valid_rows }} / {{ $b->total_rows }}</td>
                <td class="text-center"><a href="{{ route('pay.import.show', $b->id) }}" class="btn btn-light btn-icon" title="Buka preview"><i data-lucide="eye"></i></a></td></tr>
        @empty
            <tr><td colspan="6"><x-empty ikon="upload" judul="Belum ada import" pesan="Unduh template, isi datanya, lalu klik Upload File." /></td></tr>
        @endforelse
        </tbody></table></div></div>

    @if ($bukaForm)
        <x-panel judul="Upload File" sub="Pilih jenis data dan file CSV" :buka="true" :action="route('pay.import.upload')" enctype="multipart/form-data">
            <div class="mb-field"><label class="form-label">Jenis data</label>
                <select name="jenis" class="form-select" id="jenisImport">@foreach ($jenis as $j)<option value="{{ $j->slug() }}" @selected(old('jenis') === $j->slug())>{{ $j->label() }}</option>@endforeach</select></div>
            <div class="mb-field"><label class="form-label">File CSV</label><input type="file" name="file" accept=".csv,text/csv" class="form-control" required>
                @error('file')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                <div class="form-text">Pemisah koma atau titik koma.</div></div>

            <div class="fw-semibold mt-2 mb-2">Kolom template</div>
            @foreach ($jenis as $j)
                <div class="kolom-template mb-3" data-jenis="{{ $j->slug() }}">
                    <div class="small fw-semibold mb-1">{{ $j->label() }}</div>
                    <table class="table table-sm small mb-0">
                        @foreach ($j->keteranganKolom() as $kolom => $ket)<tr><td class="kode" style="width:40%">{{ $kolom }}</td><td class="text-secondary">{{ $ket }}</td></tr>@endforeach
                    </table>
                    @if ($j === \App\Domain\Payroll\Import\JenisKehadiran::Leave)
                        <div class="small text-muted mt-1">Kode tipe leave aktif:
                            @forelse ($tipeLeave as $t)<span class="kode">{{ $t->code }}</span> ({{ $t->name }})@if (! $loop->last), @endif @empty belum ada, isi dulu di menu Tipe Leave. @endforelse</div>
                    @endif
                </div>
            @endforeach
            <script>
                // tampilkan keterangan kolom sesuai jenis data yang dipilih
                (() => { const s = document.getElementById('jenisImport'); if (!s) return;
                    const ganti = () => document.querySelectorAll('.kolom-template').forEach(el => { el.hidden = el.dataset.jenis !== s.value; });
                    s.addEventListener('change', ganti); ganti(); })();
            </script>
            <div class="mt-3"><x-catatan tipe="warning">Data hanya disimpan kalau semua baris valid. Baris yang salah ditampilkan beserta alasannya dan bisa diperbaiki di preview.</x-catatan></div>
            <div class="tombol"><a href="{{ route('pay.import.index') }}" class="btn btn-light">Batal</a><button class="btn btn-primary">Upload &amp; Validasi</button></div>
        </x-panel>
    @endif
</div>
@endsection
