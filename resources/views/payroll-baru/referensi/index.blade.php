@extends('layouts.app')
@section('title', 'Tabel Referensi')
@section('breadcrumb', 'Payroll Baru / Tabel Referensi')
@section('content')
<x-page-header judul="Tabel Referensi" sub="Tabel yang dipakai rumus lewat LOOKUP()/LOOKUP_TEXT(), mis. TER PPh21 dan kategori PTKP. Isi berversi; tabel nasional hanya diubah Super Admin.">
    @if (auth()->user()->bisa('pay-referensi', 'create'))<a href="{{ route('pay.referensi.index', ['baru' => 1]) }}" class="btn btn-primary"><i data-lucide="plus"></i> Tabel Baru</a>@endif
</x-page-header>

<div class="md-grid">
    <div class="card"><div class="table-responsive"><table class="table table-hover">
        <thead><tr><th>Kode</th><th>Nama</th><th>Lingkup</th><th>Versi terbaru</th><th></th></tr></thead>
        <tbody>
        @forelse ($tabel as $t)
            @php $v = $t->versions->first(); @endphp
            <tr><td class="kode">{{ $t->code }}</td><td>{{ $t->name }}<div class="sub text-muted">{{ $t->description }}</div></td>
                <td>{!! $t->isNasional() ? '<span class="badge badge-soft-info">Nasional</span>' : '<span class="badge badge-soft-neutral">Perusahaan</span>' !!}</td>
                <td>@if ($v) v{{ $v->version_no }} {!! badge_status($v->status === 'published' ? 'aktif' : 'draft') !!}@endif</td>
                <td>@if ($v)<a href="{{ route('pay.referensi.versi', $v->id) }}" class="btn btn-light btn-sm">Buka</a>@endif</td></tr>
        @empty
            <tr><td colspan="5"><x-empty ikon="table-2" judul="Belum ada tabel referensi" /></td></tr>
        @endforelse
        </tbody></table></div></div>

    @if (request()->has('baru'))
        <x-panel judul="Tabel Referensi Baru" sub="Versi 1 dibuat sebagai draft" :buka="true" :action="route('pay.referensi.store')">
            <div class="mb-field"><label class="form-label">Kode (dipakai di rumus)</label><input name="code" value="{{ old('code') }}" class="form-control" required placeholder="ter_bulanan"></div>
            <div class="mb-field"><label class="form-label">Nama</label><input name="name" value="{{ old('name') }}" class="form-control" required></div>
            <div class="mb-field"><label class="form-label">Keterangan</label><input name="description" value="{{ old('description') }}" class="form-control"></div>
            @if ($superAdmin)<div class="form-check mb-field"><input type="checkbox" name="nasional" value="1" class="form-check-input" id="nas"><label class="form-check-label" for="nas">Tabel nasional (berlaku untuk semua perusahaan)</label></div>@endif
            <div class="tombol"><a href="{{ route('pay.referensi.index') }}" class="btn btn-light">Batal</a><button class="btn btn-primary">Simpan</button></div>
        </x-panel>
    @endif
</div>
@endsection
