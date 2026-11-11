@extends('layouts.app')
@section('title', 'Tabel ' . $versi->table->code)
@section('breadcrumb', 'Payroll Baru / Tabel Referensi / ' . $versi->table->code)
@section('content')
@php $u = auth()->user(); $draft = $versi->bisaDiubah() && $bolehKelola; @endphp
<x-page-header :judul="$versi->table->name . ' · v' . $versi->version_no" :sub="'Kode ' . $versi->table->code . ($versi->isNasional() ? ' · nasional' : '') . ' · ' . ($versi->locked_at ? 'terkunci (dipakai payroll final)' : ($versi->status === 'published' ? 'terbit' . ($versi->effective_from ? ', berlaku sejak ' . tgl($versi->effective_from) : '') : 'draft'))">
    <a href="{{ route('pay.referensi.index') }}" class="btn btn-light">Kembali</a>
    @if ($bolehKelola && $u->bisa('pay-referensi', 'create'))
        <form method="post" action="{{ route('pay.referensi.salin', $versi->id) }}">@csrf<button class="btn btn-light">Salin ke versi baru</button></form>
    @endif
</x-page-header>
@error('versi')<div class="alert alert-danger">{{ $message }}</div>@enderror

<div class="md-grid">
    <div class="card">
        <div class="p-3 pb-0 small">Versi: @foreach ($semuaVersi as $sv)<a href="{{ route('pay.referensi.versi', $sv->id) }}" class="me-2 {{ $sv->id === $versi->id ? 'fw-bold' : '' }}">v{{ $sv->version_no }} ({{ $sv->status }})</a>@endforeach</div>
        <div class="table-responsive"><table class="table table-sm">
            <thead><tr><th>Kunci</th><th class="text-end">Dari</th><th class="text-end">Sampai</th><th class="text-end">Hasil angka</th><th>Hasil teks</th>@if ($draft)<th></th>@endif</tr></thead>
            <tbody>
            @forelse ($versi->rows as $r)
                <tr class="{{ $ubah?->id === $r->id ? 'terpilih' : '' }}"><td>{{ $r->key_value }}</td><td class="text-end">{{ $r->range_from }}</td><td class="text-end">{{ $r->range_to ?? '∞' }}</td>
                    <td class="text-end">{{ $r->result_number }}</td><td>{{ $r->result_text }}</td>
                    @if ($draft)<td class="text-nowrap"><a href="{{ route('pay.referensi.versi', ['id' => $versi->id, 'ubah' => $r->id]) }}" class="btn btn-light btn-icon btn-sm"><i data-lucide="pencil"></i></a>
                        <form method="post" action="{{ route('pay.referensi.baris.destroy', [$versi->id, $r->id]) }}" class="d-inline">@csrf @method('delete')<button class="btn btn-outline-danger btn-icon btn-sm"><i data-lucide="trash-2"></i></button></form></td>@endif</tr>
            @empty
                <tr><td colspan="6"><x-empty ikon="table-2" judul="Belum ada baris" /></td></tr>
            @endforelse
            </tbody></table></div>
        <div class="p-3 small text-muted">Cara baca: baris dengan kunci sama, lalu nilai di antara Dari dan Sampai (kosong = tak terbatas).</div>
    </div>

    @if ($draft)
        <x-panel :judul="$ubah ? 'Ubah baris' : 'Tambah baris'" sub="Versi draft" :buka="true"
                 :action="$ubah ? route('pay.referensi.baris.update', [$versi->id, $ubah->id]) : route('pay.referensi.baris.store', $versi->id)" :method="$ubah ? 'put' : 'post'">
            @foreach (['key_value' => 'Kunci (mis. A, TK/0)', 'range_from' => 'Dari (angka)', 'range_to' => 'Sampai (angka, kosong = tak terbatas)', 'result_number' => 'Hasil angka (mis. tarif 0.0025)', 'result_text' => 'Hasil teks (mis. kategori A)', 'sort_order' => 'Urutan'] as $k => $label)
                <div class="mb-field"><label class="form-label">{{ $label }}</label><input name="{{ $k }}" value="{{ old($k, $ubah?->{$k}) }}" class="form-control">@error($k)<div class="text-danger small">{{ $message }}</div>@enderror</div>
            @endforeach
            <div class="tombol">@if ($ubah)<a href="{{ route('pay.referensi.versi', $versi->id) }}" class="btn btn-light">Batal</a>@endif<button class="btn btn-primary">Simpan</button></div>
            <hr>
        </x-panel>
        <div class="card mt-3"><form method="post" action="{{ route('pay.referensi.terbitkan', $versi->id) }}" class="card-body d-flex gap-2 align-items-end">@csrf
            <div><label class="form-label small">Berlaku mulai (opsional)</label><input type="date" name="effective_from" class="form-control"></div>
            <button class="btn btn-primary">Terbitkan versi</button></form></div>
    @endif
</div>
@endsection
