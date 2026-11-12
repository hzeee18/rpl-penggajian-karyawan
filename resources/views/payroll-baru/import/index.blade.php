@extends('layouts.app')
@section('title', 'Import CSV Payroll')
@section('breadcrumb', 'Payroll Baru / Import CSV')
@section('content')
<x-page-header judul="Import CSV" sub="Unggah absensi, leave, atau lembur. Data dicek dulu di halaman preview sebelum disimpan." />

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card"><form method="post" action="{{ route('pay.import.upload') }}" enctype="multipart/form-data" class="card-body">
            @csrf
            <div class="mb-field"><label class="form-label">Jenis data</label>
                <select name="jenis" class="form-select">@foreach ($jenis as $j)<option value="{{ $j->slug() }}" @selected(old('jenis') === $j->slug())>{{ $j->label() }}</option>@endforeach</select></div>
            <div class="mb-field"><label class="form-label">File CSV</label><input type="file" name="file" accept=".csv,text/csv" class="form-control" required>
                @error('file')<div class="text-danger small mt-1">{{ $message }}</div>@enderror</div>
            <div class="small text-muted mb-3">Pakai template di bawah. Pemisah koma atau titik koma.</div>
            @if (auth()->user()->bisa('pay-import', 'create'))<button class="btn btn-primary"><i data-lucide="upload"></i> Unggah &amp; preview</button>@endif
        </form></div>

        <div class="card mt-3">
            <div class="card-header">Template CSV<span class="sub">Berisi contoh dari data perusahaan Anda (NIK, kode tipe leave). Ganti/tambah barisnya, lalu unggah.</span></div>
            <div class="card-body">
                @foreach ($jenis as $j)
                    <div class="{{ $loop->last ? '' : 'border-bottom pb-3 mb-3' }}">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-semibold">{{ $j->label() }}</span>
                            <a href="{{ route('pay.import.template', $j->slug()) }}" class="btn btn-light btn-sm"><i data-lucide="download"></i> Unduh template {{ strtolower($j->label()) }}</a>
                        </div>
                        <table class="table table-sm small mb-0">
                            @foreach ($j->keteranganKolom() as $kolom => $ket)
                                <tr><td class="kode" style="width:40%">{{ $kolom }}</td><td class="text-secondary">{{ $ket }}</td></tr>
                            @endforeach
                        </table>
                        @if ($j === \App\Domain\Payroll\Import\JenisKehadiran::Leave)
                            <div class="small text-muted mt-2">Kode tipe leave aktif:
                                @forelse ($tipeLeave as $t)<span class="kode">{{ $t->code }}</span> ({{ $t->name }})@if (! $loop->last), @endif @empty belum ada, isi dulu di menu Tipe Leave. @endforelse</div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card"><div class="table-responsive"><table class="table table-hover">
            <thead><tr><th>#</th><th>Jenis</th><th>File</th><th class="text-center">Valid</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($batch as $b)
                <tr><td>{{ $b->id }}</td><td>{{ \App\Domain\Payroll\Import\JenisKehadiran::from($b->type)->label() }}</td><td>{{ $b->file_name }}</td>
                    <td class="text-center">{{ $b->valid_rows }}/{{ $b->total_rows }}</td>
                    <td>{!! badge_status(['previewing' => 'menunggu', 'committed' => 'disetujui', 'cancelled' => 'nonaktif'][$b->status]) !!}</td>
                    <td><a href="{{ route('pay.import.show', $b->id) }}" class="btn btn-light btn-sm">Buka</a></td></tr>
            @empty
                <tr><td colspan="6"><x-empty ikon="upload" judul="Belum ada import" /></td></tr>
            @endforelse
            </tbody></table></div></div>
    </div>
</div>
@endsection
