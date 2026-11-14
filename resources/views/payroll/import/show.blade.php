@extends('layouts.app')
@section('title', 'Preview Import')
@section('breadcrumb', 'Payroll / Import CSV / Preview')
@section('content')
@php
    $u = auth()->user(); $preview = $batch->status === 'previewing';
    $judulKolom = fn ($k) => ['nik' => 'NIK', 'kode_tipe' => 'Kode tipe', 'jam_terlambat' => 'Terlambat (jam)', 'durasi_jam' => 'Durasi (jam)'][$k] ?? ucfirst(str_replace('_', ' ', $k));
@endphp
<x-page-header :judul="'Preview Import ' . $jenis->label() . ' · IM-' . $batch->id" :sub="$batch->file_name . ' · diunggah ' . tgl($batch->created_at, 'j M Y H:i')">
    <a href="{{ route('pay.import.index') }}" class="btn btn-light">Kembali</a>
    @if ($preview && $u->bisa('pay-import', 'delete'))
        <form method="post" action="{{ route('pay.import.batal', $batch->id) }}" data-confirm="Batalkan import ini?">@csrf<button class="btn btn-light">Batalkan</button></form>
    @endif
    @if ($preview && $u->bisa('pay-import', 'create'))
        <form method="post" action="{{ route('pay.import.simpan', $batch->id) }}">@csrf
            <button class="btn btn-primary" @disabled($batch->valid_rows !== $batch->total_rows || $batch->total_rows === 0)>Simpan {{ $batch->total_rows }} baris</button></form>
    @endif
</x-page-header>
@error('batch')<div class="mb-3"><x-catatan tipe="danger">{{ $message }}</x-catatan></div>@enderror
<div class="stat-row">
    @foreach ([['Total baris', $batch->total_rows, ''], ['Valid', $batch->valid_rows, 'text-success'], ['Error', $batch->total_rows - $batch->valid_rows, $batch->total_rows - $batch->valid_rows ? 'text-danger' : ''],
        ['Status', ['previewing' => 'Preview', 'committed' => 'Tersimpan', 'cancelled' => 'Dibatalkan'][$batch->status], '']] as [$l, $v, $c])
        <div class="card stat-card"><div class="card-body py-3"><div class="label">{{ $l }}</div><div class="angka fs-4 mt-1 {{ $c }}">{{ $v }}</div></div></div>
    @endforeach
</div>
@if ($preview && $batch->valid_rows !== $batch->total_rows)
    <div class="mb-3"><x-catatan tipe="danger">Ada baris error (merah). Perbaiki atau hapus baris tersebut sebelum menyimpan.</x-catatan></div>
@endif

<div class="md-grid">
    <div class="card"><div class="table-responsive"><table class="table">
        <thead><tr><th>Baris</th>@foreach ($jenis->kolom() as $k)<th>{{ $judulKolom($k) }}</th>@if ($k === 'nik')<th>Nama karyawan</th>@endif @endforeach<th>Hasil cek</th>@if ($preview)<th class="text-center">Aksi</th>@endif</tr></thead>
        <tbody>
        @foreach ($batch->rows as $row)
            <tr class="{{ $row->is_valid ? '' : 'table-danger' }} {{ $ubah?->id === $row->id ? 'terpilih' : '' }}">
                <td class="text-muted">{{ $row->row_no }}</td>
                @foreach ($jenis->kolom() as $k)<td class="{{ $k === 'nik' ? 'kode' : '' }}">{{ $row->payload[$k] ?? '' }}</td>
                    @if ($k === 'nik')<td>{!! isset($namaKaryawan[$row->payload['nik'] ?? '']) ? e($namaKaryawan[$row->payload['nik']]) : '<span class="text-muted">tidak ditemukan</span>' !!}</td>@endif
                @endforeach
                <td class="small">@if ($row->is_valid)<span class="badge badge-soft-success">OK</span>@else<span class="text-danger">{{ $row->error_message }}</span>@endif</td>
                @if ($preview)<td class="text-nowrap text-center"><div class="d-inline-flex gap-2 justify-content-center">
                    @if ($u->bisa('pay-import', 'update'))<a href="{{ route('pay.import.show', ['id' => $batch->id, 'ubah' => $row->id]) }}" class="btn btn-light btn-icon" title="Perbaiki"><i data-lucide="pencil"></i></a>@endif
                    @if ($u->bisa('pay-import', 'delete'))<form method="post" action="{{ route('pay.import.baris.destroy', [$batch->id, $row->id]) }}" data-confirm="Hapus baris {{ $row->row_no }}?">@csrf @method('delete')<button class="btn btn-outline-danger btn-icon" title="Hapus baris"><i data-lucide="trash-2"></i></button></form>@endif
                </div></td>@endif
            </tr>
        @endforeach
        </tbody></table></div></div>

    @if ($ubah && $preview)
        <x-panel judul="Perbaiki baris" :sub="'Baris ' . $ubah->row_no" :buka="true" :action="route('pay.import.baris.update', [$batch->id, $ubah->id])" method="put">
            @foreach ($jenis->kolom() as $k)
                <div class="mb-field"><label class="form-label">{{ $judulKolom($k) }}</label><input name="{{ $k }}" value="{{ $ubah->payload[$k] ?? '' }}" class="form-control"></div>
            @endforeach
            <div class="tombol"><a href="{{ route('pay.import.show', $batch->id) }}" class="btn btn-light">Batal</a><button class="btn btn-primary">Simpan &amp; cek ulang</button></div>
        </x-panel>
    @endif
</div>
@endsection
