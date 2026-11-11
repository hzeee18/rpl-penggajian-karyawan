@extends('layouts.app')
@section('title', 'Preview Import')
@section('breadcrumb', 'Payroll Baru / Import CSV / Preview')
@section('content')
@php $u = auth()->user(); $preview = $batch->status === 'previewing'; @endphp
<x-page-header :judul="'Preview Import ' . $jenis->label()" :sub="$batch->file_name . ' · ' . $batch->valid_rows . ' dari ' . $batch->total_rows . ' baris valid'">
    <a href="{{ route('pay.import.index') }}" class="btn btn-light">Kembali</a>
    @if ($preview && $u->bisa('pay-import', 'delete'))
        <form method="post" action="{{ route('pay.import.batal', $batch->id) }}" data-confirm="Batalkan import ini?">@csrf<button class="btn btn-light">Batalkan</button></form>
    @endif
    @if ($preview && $u->bisa('pay-import', 'create'))
        <form method="post" action="{{ route('pay.import.simpan', $batch->id) }}">@csrf
            <button class="btn btn-primary" @disabled($batch->valid_rows !== $batch->total_rows || $batch->total_rows === 0)>Simpan {{ $batch->total_rows }} baris</button></form>
    @endif
</x-page-header>
@error('batch')<div class="alert alert-danger">{{ $message }}</div>@enderror
@if ($preview && $batch->valid_rows !== $batch->total_rows)
    <div class="alert alert-warning">Ada baris error (merah). Perbaiki atau hapus baris tersebut sebelum menyimpan.</div>
@endif

<div class="md-grid">
    <div class="card"><div class="table-responsive"><table class="table">
        <thead><tr><th>Baris</th>@foreach ($jenis->kolom() as $k)<th>{{ $k }}</th>@endforeach<th>Keterangan</th>@if ($preview)<th>Aksi</th>@endif</tr></thead>
        <tbody>
        @foreach ($batch->rows as $row)
            <tr class="{{ $row->is_valid ? '' : 'table-danger' }} {{ $ubah?->id === $row->id ? 'terpilih' : '' }}">
                <td>{{ $row->row_no }}</td>
                @foreach ($jenis->kolom() as $k)<td>{{ $row->payload[$k] ?? '' }}</td>@endforeach
                <td class="small">{{ $row->is_valid ? 'OK' : $row->error_message }}</td>
                @if ($preview)<td class="text-nowrap"><div class="d-inline-flex gap-2">
                    @if ($u->bisa('pay-import', 'update'))<a href="{{ route('pay.import.show', ['id' => $batch->id, 'ubah' => $row->id]) }}" class="btn btn-light btn-icon" title="Perbaiki"><i data-lucide="pencil"></i></a>@endif
                    @if ($u->bisa('pay-import', 'delete'))<form method="post" action="{{ route('pay.import.baris.destroy', [$batch->id, $row->id]) }}">@csrf @method('delete')<button class="btn btn-outline-danger btn-icon" title="Hapus baris"><i data-lucide="trash-2"></i></button></form>@endif
                </div></td>@endif
            </tr>
        @endforeach
        </tbody></table></div></div>

    @if ($ubah && $preview)
        <x-panel judul="Perbaiki baris" :sub="'Baris ' . $ubah->row_no" :buka="true" :action="route('pay.import.baris.update', [$batch->id, $ubah->id])" method="put">
            @foreach ($jenis->kolom() as $k)
                <div class="mb-field"><label class="form-label">{{ $k }}</label><input name="{{ $k }}" value="{{ $ubah->payload[$k] ?? '' }}" class="form-control"></div>
            @endforeach
            <div class="tombol"><a href="{{ route('pay.import.show', $batch->id) }}" class="btn btn-light">Batal</a><button class="btn btn-primary">Simpan &amp; cek ulang</button></div>
        </x-panel>
    @endif
</div>
@endsection
