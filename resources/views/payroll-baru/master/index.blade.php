@extends('layouts.app')
@section('title', 'Master Payroll')
@section('breadcrumb', 'Payroll Baru / Master Payroll')
@section('content')
<x-page-header judul="Master Payroll" sub="Konfigurasi rumus payroll (mis. Payroll Usual, Payroll THR). Setiap perubahan dibuat di versi baru.">
    @if (auth()->user()->bisa('pay-master', 'create'))<a href="{{ route('pay.master.index', ['baru' => 1]) }}" class="btn btn-primary"><i data-lucide="plus"></i> Master Baru</a>@endif
</x-page-header>

<div class="md-grid">
    <div class="card"><div class="table-responsive"><table class="table table-hover">
        <thead><tr><th>Kode</th><th>Nama</th><th>Versi aktif</th><th>Semua versi</th></tr></thead>
        <tbody>
        @forelse ($master as $m)
            <tr><td class="kode">{{ $m->code }}</td><td>{{ $m->name }}<div class="sub text-muted">{{ $m->description }}</div></td>
                <td>{{ $m->currentVersion ? 'v' . $m->currentVersion->version_no : 'belum terbit' }}</td>
                <td>@foreach ($m->versions as $v)<a href="{{ route('pay.master.versi', $v->id) }}" class="me-2">v{{ $v->version_no }} <span class="text-muted small">({{ $v->locked_at ? 'terkunci' : $v->status }})</span></a>@endforeach</td></tr>
        @empty
            <tr><td colspan="4"><x-empty ikon="sigma" judul="Belum ada master payroll" /></td></tr>
        @endforelse
        </tbody></table></div></div>

    @if (request()->has('baru'))
        <x-panel judul="Master Payroll Baru" sub="Versi 1 dibuat sebagai draft" :buka="true" :action="route('pay.master.store')">
            <div class="mb-field"><label class="form-label">Kode</label><input name="code" value="{{ old('code') }}" class="form-control" required placeholder="usual"></div>
            <div class="mb-field"><label class="form-label">Nama</label><input name="name" value="{{ old('name') }}" class="form-control" required placeholder="Payroll Usual"></div>
            <div class="mb-field"><label class="form-label">Keterangan</label><input name="description" value="{{ old('description') }}" class="form-control"></div>
            <div class="tombol"><a href="{{ route('pay.master.index') }}" class="btn btn-light">Batal</a><button class="btn btn-primary">Simpan</button></div>
        </x-panel>
    @endif
</div>
@endsection
